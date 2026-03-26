<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Helpers\HelperStatus;
use Illuminate\Http\Response;
use App\Mail\WithdrawalRequestApprovedMail;
use App\Mail\WithdrawalRequestRejectedMail;
use App\Mail\WithdrawalRequestSubmittedMail;
use App\Models\User;
use App\Services\WalletService;
use App\Classes\ApiResponseClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\WithdrawalRequestResource;
use App\Interfaces\WithdrawalRequestRepositoryInterface;
use App\Enums\RoleEnum;

class WithdrawalRequestController extends Controller
{
    private $withdrawalRequestRepository;
    private $walletService;

    public function __construct(
        WithdrawalRequestRepositoryInterface $withdrawalRequestRepository,
        WalletService $walletService
    ) {
        $this->withdrawalRequestRepository = $withdrawalRequestRepository;
        $this->walletService = $walletService;
    }

    /**
     * Créer une demande de retrait
     * POST /api/withdrawal-requests
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'wallet_id' => 'required|exists:wallets,id',
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|integer|min:1000', // Minimum 1000 GNF
            'description' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        DB::beginTransaction();
        try {
            // ✅ : Vérifier d'abord si le retrait est possible
            $canWithdraw = $this->walletService->canCreateWithdrawalRequest(
                $request->user_id, 
                $request->amount
            );

            if (!$canWithdraw) {
                $availableBalance = $this->walletService->getAvailableWithdrawalBalance(
                    $request->user_id,
                );
                
                return ApiResponseClass::sendError(
                    "Solde disponible insuffisant. Disponible: {$availableBalance} GNF, Demandé: {$request->amount} GNF",
                    null,
                    400
                );
            }

            $result = $this->walletService->withdrawalRequest(
                $request->user_id, // from_user_id
                $request->user_id, // to_user_id (même utilisateur pour un retrait)
                $request->amount,
                $request->description,
                $request->metadata
            );

            if (!$result['success']) {
                throw new \Exception($result['message'] ?? 'Erreur lors de la création de la demande');
            }

            // Gérer les pièces jointes si fournies
            if ($request->has('attachments') && !empty($request->attachments)) {
                $withdrawalRequest = $this->withdrawalRequestRepository->getById($result['withdrawal_request_id']);
                if ($withdrawalRequest) {
                    foreach ($request->attachments as $attachment) {
                        $withdrawalRequest->attachments()->create($attachment);
                    }
                }
            }

            DB::commit();

            // Notifier l'admin par email - best effort
            try {
                $requester = User::find($request->user_id);
                if ($requester instanceof User) {
                    $wr = $this->withdrawalRequestRepository->getById($result['withdrawal_request_id']);
                    if ($wr) {
                        $adminEmails = $this->resolveWithdrawalAdminRecipients($requester);
                        if (!empty($adminEmails)) {
                            Mail::to($adminEmails)->send(new WithdrawalRequestSubmittedMail($wr, $requester));
                        }
                    }
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur envoi email (nouvelle demande de retrait): ' . $mailException->getMessage());
            }

            return ApiResponseClass::sendResponse([
                'withdrawal_request_id' => $result['withdrawal_request_id'],
                'status' => $result['status'],
                'amount' => $request->amount,
                'currency' => 'GNF',
                'blocked_amount' => $result['blocked_amount'] ?? 0,
                'available_balance' => $result['available_balance'] ?? 0,
                'created_at' => now()->toISOString()
            ], $result['message']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur WithdrawalRequestController@store: " . $e->getMessage(), [
                'wallet_id' => $request->wallet_id,
                'user_id' => $request->user_id,
                'amount' => $request->amount,
            ]);

            return ApiResponseClass::sendError(
                "Erreur lors de la création de la demande de retrait: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    /**
     * Créer une demande de retrait sécurisée avec validation des rôles
     * POST /api/withdrawal-requests/secured
     */
    public function storeSecured(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from_user_id' => 'required|exists:users,id',
            'to_user_id' => 'required|exists:users,id',
            'amount' => 'required|integer|min:1000',
            'description' => 'nullable|string|max:500',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        DB::beginTransaction();
        try {
            $result = $this->walletService->securedWithdrawalRequest(
                $request->from_user_id,
                $request->to_user_id,
                $request->amount,
                $request->description,
                $request->metadata
            );

            if (!$result['success']) {
                throw new \Exception($result['message'] ?? 'Erreur lors de la création de la demande sécurisée');
            }

            DB::commit();

            return ApiResponseClass::sendResponse([
                'withdrawal_request_id' => $result['withdrawal_request_id'],
                'status' => $result['status'],
                'amount' => $request->amount,
                'from_user_id' => $request->from_user_id,
                'to_user_id' => $request->to_user_id,
                'blocked_amount' => $result['blocked_amount'] ?? 0,
                'created_at' => now()->toISOString()
            ], $result['message']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur WithdrawalRequestController@storeSecured: " . $e->getMessage(), [
                'from_user_id' => $request->from_user_id,
                'to_user_id' => $request->to_user_id,
                'amount' => $request->amount
            ]);

            return ApiResponseClass::sendError(
                "Erreur lors de la création de la demande de retrait sécurisée: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    /**
     * Lister toutes les demandes de retrait (avec filtres)
     * GET /api/withdrawal-requests
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'wallet_id' => 'nullable|exists:wallets,id',
            'status' => 'nullable|string|in:PENDING,APPROVED,REJECTED,CANCELLED', // ✅ CORRECTION : majuscules
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'with_user' => 'boolean',
            'with_wallet' => 'boolean',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $perPage = $request->per_page ?? 15;

            $query = $this->withdrawalRequestRepository->getQuery();

            // Appliquer les filtres
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->has('wallet_id')) {
                $query->where('wallet_id', $request->wallet_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('start_date')) {
                $query->where('created_at', '>=', $request->start_date);
            }

            if ($request->has('end_date')) {
                $query->where('created_at', '<=', $request->end_date);
            }

            // Tri par défaut
            $query->orderBy('created_at', 'desc');

            $withdrawalRequests = $query->paginate($perPage);

            return ApiResponseClass::sendResponse([
                'withdrawal_requests' => WithdrawalRequestResource::collection($withdrawalRequests),
                'pagination' => [
                    'total' => $withdrawalRequests->total(),
                    'per_page' => $withdrawalRequests->perPage(),
                    'current_page' => $withdrawalRequests->currentPage(),
                    'last_page' => $withdrawalRequests->lastPage()
                ]
            ], 'Demandes de retrait récupérées avec succès');

        } catch (\Exception $e) {
            Log::error("Erreur WithdrawalRequestController@index: " . $e->getMessage());
            return ApiResponseClass::sendError(
                "Erreur lors de la récupération des demandes de retrait: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    /**
     * Afficher une demande de retrait spécifique
     * GET /api/withdrawal-requests/{id}
     */
    public function show(string $id)
    {
        try {
            $withdrawalRequest = $this->withdrawalRequestRepository->getById($id);
            
            if (!$withdrawalRequest) {
                return ApiResponseClass::notFound('Demande de retrait introuvable');
            }

            return ApiResponseClass::sendResponse(
                new WithdrawalRequestResource($withdrawalRequest),
                'Demande de retrait récupérée avec succès'
            );

        } catch (\Exception $e) {
            Log::error("Erreur WithdrawalRequestController@show: " . $e->getMessage(), ['id' => $id]);
            return ApiResponseClass::sendError(
                "Erreur lors de la récupération de la demande de retrait: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    /**
     * Approuver une demande de retrait
     * POST /api/withdrawal-requests/{id}/approve
     */
    public function approve(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'processed_by' => 'required|exists:users,id',
            'processing_notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            // Utiliser la méthode du service pour approuver
            $result = $this->walletService->approveWithdrawal(
                $id,
                $request->processed_by,
                $request->processing_notes
            );

            if (!$result['success']) {
                throw new \Exception($result['message'] ?? 'Erreur lors de l\'approbation');
            }

            // Récupérer la demande mise à jour
            $updatedRequest = $this->withdrawalRequestRepository->getById($id);

            // Notifier le demandeur par email - best effort
            try {
                if ($updatedRequest) {
                    $updatedRequest->loadMissing(['user']);
                    $requester = $updatedRequest->user;
                    $approver  = User::find($request->processed_by);
                    if ($requester instanceof User && !empty($requester->email)) {
                        Mail::to($requester->email)->send(new WithdrawalRequestApprovedMail($updatedRequest, $requester, $approver));
                    }
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur envoi email (retrait approuvé): ' . $mailException->getMessage());
            }

            return ApiResponseClass::sendResponse([
                'withdrawal_request' => new WithdrawalRequestResource($updatedRequest),
                'action' => HelperStatus::APPROVED,
                'processed_by' => $request->processed_by,
                'processed_at' => now()->toISOString(),
                'blocked_amount' => $result['blocked_amount'] ?? 0,
                'cash_available' => $result['cash_available'] ?? 0
            ], $result['message']);

        } catch (\Exception $e) {
            Log::error("Erreur WithdrawalRequestController@approve: " . $e->getMessage(), [
                'withdrawal_request_id' => $id,
                'processed_by' => $request->processed_by
            ]);

            return ApiResponseClass::sendError(
                "Erreur lors de l'approbation de la demande de retrait: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    /**
     * Rejeter une demande de retrait
     * POST /api/withdrawal-requests/{id}/reject
     */
    public function reject(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'processed_by' => 'required|exists:users,id',
            'processing_notes' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            // Utiliser la méthode du service pour rejeter
            $result = $this->walletService->rejectWithdrawal(
                $id,
                $request->processed_by,
                $request->processing_notes
            );

            if (!$result['success']) {
                throw new \Exception($result['message'] ?? 'Erreur lors du rejet');
            }

            // Récupérer la demande mise à jour
            $updatedRequest = $this->withdrawalRequestRepository->getById($id);

            // Notifier le demandeur par email - best effort
            try {
                if ($updatedRequest) {
                    $updatedRequest->loadMissing(['user']);
                    $requester = $updatedRequest->user;
                    if ($requester instanceof User && !empty($requester->email)) {
                        Mail::to($requester->email)->send(new WithdrawalRequestRejectedMail($updatedRequest, $requester, $request->processing_notes));
                    }
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur envoi email (retrait rejeté): ' . $mailException->getMessage());
            }

            return ApiResponseClass::sendResponse([
                'withdrawal_request' => new WithdrawalRequestResource($updatedRequest),
                'action' => HelperStatus::REJECTED,
                'processed_by' => $request->processed_by,
                'processed_at' => now()->toISOString(),
                'blocked_amount' => $result['blocked_amount'] ?? 0
            ], $result['message']);

        } catch (\Exception $e) {
            Log::error("Erreur WithdrawalRequestController@reject: " . $e->getMessage(), [
                'withdrawal_request_id' => $id,
                'processed_by' => $request->processed_by
            ]);

            return ApiResponseClass::sendError(
                "Erreur lors du rejet de la demande de retrait: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    /**
     * Annuler une demande de retrait
     * POST /api/withdrawal-requests/{id}/cancel
     */
    public function cancel(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'cancelled_by' => 'required|exists:users,id',
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            // Utiliser la méthode du service pour annuler
            $result = $this->walletService->cancelWithdrawal(
                $id,
                $request->cancelled_by,
                $request->reason
            );

            if (!$result['success']) {
                throw new \Exception($result['message'] ?? 'Erreur lors de l\'annulation');
            }

            // Récupérer la demande mise à jour
            $updatedRequest = $this->withdrawalRequestRepository->getById($id);

            return ApiResponseClass::sendResponse([
                'withdrawal_request' => new WithdrawalRequestResource($updatedRequest),
                'action' => HelperStatus::CANCELLED,
                'cancelled_by' => $request->cancelled_by,
                'cancelled_at' => now()->toISOString(),
                'blocked_amount' => $result['blocked_amount'] ?? 0
            ], $result['message']);

        } catch (\Exception $e) {
            Log::error("Erreur WithdrawalRequestController@cancel: " . $e->getMessage(), [
                'withdrawal_request_id' => $id,
                'cancelled_by' => $request->cancelled_by
            ]);

            return ApiResponseClass::sendError(
                "Erreur lors de l'annulation de la demande de retrait: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    /**
     * Récupérer les statistiques des demandes de retrait
     * GET /api/withdrawal-requests/stats/overview
     */
    public function getStats(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|string|in:day,week,month,year',
            'user_id' => 'nullable|exists:users,id'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $period = $request->period ?? 'month';
            $userId = $request->user_id;

            if ($userId) {
                $stats = $this->walletService->getWithdrawalStats($userId, $period);
            } else {
                // Statistiques globales
                $stats = $this->withdrawalRequestRepository->getStats($period);
            }

            return ApiResponseClass::sendResponse([
                'stats' => $stats,
                'period' => $period,
                'user_id' => $userId,
                'calculated_at' => now()->toISOString()
            ], 'Statistiques des demandes de retrait récupérées avec succès');

        } catch (\Exception $e) {
            Log::error("Erreur WithdrawalRequestController@getStats: " . $e->getMessage());
            return ApiResponseClass::sendError(
                "Erreur lors de la récupération des statistiques: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    /**
     * Récupérer les statistiques d'un utilisateur
     * GET /api/withdrawal-requests/stats/user/{userId}
     */
    public function getUserStats(Request $request, string $userId)
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|string|in:day,week,month,year'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $authorizedUserId = $this->resolveAuthorizedWithdrawalUserId($userId);
            if ($authorizedUserId instanceof \Illuminate\Http\JsonResponse) {
                return $authorizedUserId;
            }

            $period = $request->period ?? 'month';
            $stats = $this->walletService->getWithdrawalStats($authorizedUserId, $period);

            return ApiResponseClass::sendResponse([
                'user_id' => $authorizedUserId,
                'stats' => $stats,
                'period' => $period,
                'calculated_at' => now()->toISOString()
            ], 'Statistiques utilisateur récupérées avec succès');

        } catch (\Exception $e) {
            Log::error("Erreur WithdrawalRequestController@getUserStats: " . $e->getMessage(), ['user_id' => $userId]);
            return ApiResponseClass::sendError(
                "Erreur lors de la récupération des statistiques utilisateur: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    /**
     * Récupérer l'historique des demandes pour un utilisateur
     * GET /api/withdrawal-requests/user/{userId}/history
     */
    public function getUserHistory(Request $request, string $userId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string|in:PENDING,APPROVED,REJECTED,CANCELLED', // ✅ CORRECTION : majuscules
            'provider' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'per_page' => 'sometimes|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $authorizedUserId = $this->resolveAuthorizedWithdrawalUserId($userId);
            if ($authorizedUserId instanceof \Illuminate\Http\JsonResponse) {
                return $authorizedUserId;
            }

            $perPage = $request->per_page ?? 15;
            
            $filters = [
                'status' => $request->status,
                'provider' => $request->provider,
                'date_from' => $request->start_date,
                'date_to' => $request->end_date,
                'per_page' => $perPage
            ];

            // Utiliser la méthode du service pour récupérer l'historique
            $withdrawalRequests = $this->walletService->getWithdrawalRequests($authorizedUserId, $filters);

            // Récupérer les statistiques
            $userStats = $this->walletService->getWithdrawalStats($authorizedUserId);

            return ApiResponseClass::sendResponse([
                'user_id' => $authorizedUserId,
                'withdrawal_requests' => WithdrawalRequestResource::collection($withdrawalRequests),
                'user_stats' => $userStats,
                'pagination' => [
                    'total' => $withdrawalRequests->total(),
                    'per_page' => $withdrawalRequests->perPage(),
                    'current_page' => $withdrawalRequests->currentPage(),
                    'last_page' => $withdrawalRequests->lastPage()
                ]
            ], 'Historique des demandes de retrait récupéré avec succès');

        } catch (\Exception $e) {
            Log::error("Erreur WithdrawalRequestController@getUserHistory: " . $e->getMessage(), ['user_id' => $userId]);
            return ApiResponseClass::sendError(
                "Erreur lors de la récupération de l'historique utilisateur: " . $e->getMessage(),
                null,
                400
            );
        }
    }

    private function resolveAuthorizedWithdrawalUserId(string $requestedUserId): string|\Illuminate\Http\JsonResponse
    {
        $authenticatedUser = Auth::guard('api')->user();

        if (!$authenticatedUser instanceof User) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié');
        }

        $roleSlug = $authenticatedUser->role?->slug;
        $isPrivilegedUser = $authenticatedUser->role?->is_super_admin === true
            || ($roleSlug !== null && !in_array($roleSlug, [RoleEnum::CLIENT, RoleEnum::PRO, RoleEnum::API_CLIENT], true));

        if ($isPrivilegedUser) {
            return $requestedUserId;
        }

        if ((string) $authenticatedUser->id !== $requestedUserId) {
            Log::warning('Tentative d\'accès à des retraits d\'un autre utilisateur', [
                'authenticated_user_id' => $authenticatedUser->id,
                'requested_user_id' => $requestedUserId,
            ]);

            return ApiResponseClass::forbidden('Vous ne pouvez consulter que vos propres retraits');
        }

        return (string) $authenticatedUser->id;
    }

    /**
     * Résout les destinataires admin pour les demandes de retrait.
     * Priorité : sous-admin assigné au demandeur > tous les super-admins.
     */
    private function resolveWithdrawalAdminRecipients(User $requester): array
    {
        if (!empty($requester->assigned_user)) {
            $assigned = User::find($requester->assigned_user);
            if ($assigned instanceof User && !empty($assigned->email)) {
                return [$assigned->email];
            }
        }

        return User::whereHas('role', function ($query) {
            $query->where('is_super_admin', true);
        })
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Vérifier le solde disponible pour un retrait
     * GET /api/withdrawal-requests/check-balance
     */
    public function checkBalance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|integer',
            'provider' => 'required|string|in:' . implode(',', [
                HelperStatus::SOURCE_EDG,
                HelperStatus::SOURCE_GSS,
                HelperStatus::SOURCE_CASH,
            ])
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $availableBalance = $this->walletService->getAvailableWithdrawalBalance(
                $request->user_id, 
                $request->provider
            );

            $canWithdraw = $this->walletService->canCreateWithdrawalRequest(
                $request->user_id, 
                $request->amount, 
                $request->provider
            );

            return ApiResponseClass::sendResponse([
                'user_id' => $request->user_id,
                'requested_amount' => $request->amount,
                'available_balance' => $availableBalance,
                'can_withdraw' => $canWithdraw,
                'currency' => 'GNF',
                'checked_at' => now()->toISOString()
            ], 'Vérification du solde effectuée avec succès');

        } catch (\Exception $e) {
            Log::error("Erreur WithdrawalRequestController@checkBalance: " . $e->getMessage(), [
                'user_id' => $request->user_id,
                'amount' => $request->amount
            ]);

            return ApiResponseClass::sendError(
                "Erreur lors de la vérification du solde: " . $e->getMessage(),
                null,
                400
            );
        }
    }
}