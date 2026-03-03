<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\DemandeProRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Helpers\HelperStatus;
use App\Mail\TopupRequestApprovedMail;
use App\Mail\TopupRequestSubmittedMail;
use App\Http\Requests\TopupRequest\CreateTopupRequestRequest;
use App\Http\Requests\TopupRequest\UpdateStatusRequest;
use App\Http\Requests\TopupRequest\UpdateTopupRequestRequest;
use App\Http\Resources\TopupRequestResource;
use App\Interfaces\TopupRequestRepositoryInterface;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\TopupRequestService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TopupRequestController extends Controller
{
    private TopupRequestRepositoryInterface $topupRequestRepository;
    private WalletService $walletService;
    protected TopupRequestService $topupRequestService;

    public function __construct(
        TopupRequestRepositoryInterface $topupRequestRepository,
        WalletService $walletService,
        TopupRequestService $topupRequestService
    ) {
        $this->topupRequestRepository = $topupRequestRepository;
        $this->walletService = $walletService;
         $this->topupRequestService = $topupRequestService;
    }

    /**
     * 📋 Liste toutes les demandes de recharge (avec pagination et filtres)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);

            $filters = $request->only([
                'status',
                'kind',
                'pro_id',
                'date_from',
                'date_to',
                'amount_min',
                'amount_max'
            ]);

            if (!empty($filters)) {
                $topupRequests = $this->topupRequestRepository->searchWithFilters($filters, $perPage);
            } else {
                $topupRequests = $this->topupRequestRepository->getAll($perPage);
            }

            return ApiResponseClass::sendResponse(
                TopupRequestResource::collection($topupRequests)->response()->getData(true),
                'Demandes de recharge récupérées avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des demandes de recharge: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des demandes');
        }
    }

    /**
     * ➕ Créer une nouvelle demande de recharge
     */
    public function store(CreateTopupRequestRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            // Vérifier la clé d'idempotence
            // if ($this->topupRequestRepository->idempotencyKeyExists($request->idempotency_key)) {
            //     return ApiResponseClass::sendError(
            //         'Demande déjà existante',
            //         ['idempotency_key' => 'Une demande avec cette clé existe déjà'],
            //         Response::HTTP_CONFLICT
            //     );
            // }

            $topupRequest = $this->topupRequestRepository->create($request->all());

            DB::commit();

            // Notifier l'admin (ou sous-admin assigné) par email - best effort
            try {
                $topupRequest->loadMissing(['pro']);
                $requester = $topupRequest->pro;

                if ($requester instanceof User) {
                    $recipients = $this->resolveTopupAdminRecipients($requester);
                    if (!empty($recipients)) {
                        Mail::to($recipients)->send(new TopupRequestSubmittedMail($topupRequest, $requester));
                    }
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur envoi email (nouvelle demande de recharge): ' . $mailException->getMessage());
            }

            return ApiResponseClass::sendResponse(
                new TopupRequestResource($topupRequest),
                'Demande de recharge créée avec succès',
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de la demande de recharge: ' . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de la création de la demande' . $e->getMessage());
        }
    }

    /**
     * 👁️ Afficher une demande de recharge spécifique
     */
    public function show(string $id): JsonResponse
    {
        try {
            $topupRequest = $this->topupRequestRepository->getByID($id);

            if (!$topupRequest) {
                return ApiResponseClass::notFound('Demande de recharge non trouvée');
            }

            return ApiResponseClass::sendResponse(
                new TopupRequestResource($topupRequest),
                'Demande de recharge récupérée avec succès'
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération de la demande $id: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération de la demande');
        }
    }

    /**
     * ✏️ Mettre à jour une demande de recharge
     */
    public function update(UpdateTopupRequestRequest $request, string $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $topupRequest = $this->topupRequestRepository->getByID($id);

            if (!$topupRequest) {
                return ApiResponseClass::notFound('Demande de recharge non trouvée');
            }

            // Vérifier que l'utilisateur peut modifier cette demande
            // if ($topupRequest->pro_id !== auth()->id() && !auth()->user()->isAdmin()) {
            //     return ApiResponseClass::sendError(
            //         'Action non autorisée',
            //         ['message' => 'Vous ne pouvez pas modifier cette demande'],
            //         Response::HTTP_FORBIDDEN
            //     );
            // }

            // Empêcher la modification si la demande n'est plus en attente
            if ($topupRequest->status !== HelperStatus::PENDING) {
                return ApiResponseClass::sendError(
                    'Demande non modifiable',
                    ['message' => 'Seules les demandes en attente peuvent être modifiées'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $success = $this->topupRequestRepository->update($id, $request->validated());

            if ($success) {
                $updatedTopupRequest = $this->topupRequestRepository->getByID($id);
                DB::commit();

                return ApiResponseClass::sendResponse(
                    new TopupRequestResource($updatedTopupRequest),
                    'Demande de recharge mise à jour avec succès'
                );
            } else {
                DB::rollBack();
                return ApiResponseClass::sendError(
                    'Erreur lors de la mise à jour',
                    ['message' => 'Impossible de mettre à jour la demande'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de la mise à jour de la demande $id: " . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de la mise à jour de la demande');
        }
    }

    /**
     * 🗑️ Supprimer une demande de recharge (soft delete)
     */
    public function destroy(string $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $topupRequest = $this->topupRequestRepository->getByID($id);

            if (!$topupRequest) {
                return ApiResponseClass::notFound('Demande de recharge non trouvée');
            }

            // Vérifier les permissions
            // if ($topupRequest->pro_id !== auth()->id() && !auth()->user()->isAdmin()) {
            //     return ApiResponseClass::sendError(
            //         'Action non autorisée',
            //         ['message' => 'Vous ne pouvez pas supprimer cette demande'],
            //         Response::HTTP_FORBIDDEN
            //     );
            // }

            $success = $this->topupRequestRepository->delete($id);

            if ($success) {
                DB::commit();
                return ApiResponseClass::sendResponse(
                    null,
                    'Demande de recharge supprimée avec succès'
                );
            } else {
                DB::rollBack();
                return ApiResponseClass::sendError(
                    'Erreur lors de la suppression',
                    ['message' => 'Impossible de supprimer la demande'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de la suppression de la demande $id: " . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de la suppression de la demande');
        }
    }

    /**
     * 🔍 Récupérer les demandes de recharge par utilisateur pro
     */
    public function findByUser(Request $request, string $userId): JsonResponse
    {
        try {

            $perPage = $request->get('per_page', 15);
            $filters = $request->only(['status', 'kind', 'pro_id', 'date_from', 'date_to', 'amount_min', 'amount_max']);

            if (!empty($filters)) {
                $topupRequests = $this->topupRequestRepository->searchWithFilters($filters, $perPage);
            } else {
                $topupRequests = $this->topupRequestRepository->findByUser($userId);
            }


            return ApiResponseClass::sendResponse(
                TopupRequestResource::collection($topupRequests)->response()->getData(true),
                'Demandes de recharge récupérées avec succès'
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des demandes pour l'utilisateur $userId: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des demandes');
        }
    }

    /**
     * 🔍 Récupérer les demandes de recharge par utilisateur pro
     */
    public function findByUserWhere(Request $request, string $userId): JsonResponse
    {
        try {

            $perPage = $request->get('per_page', 15);
            $filters = $request->only(['status', 'kind', 'pro_id', 'date_from', 'date_to', 'amount_min', 'amount_max']);

            if (!empty($filters)) {
                $topupRequests = $this->topupRequestRepository->searchWithFiltersAndWhere($userId, $filters, $perPage);
            } else {
                $topupRequests = $this->topupRequestRepository->findByUser($userId);
            }


            return ApiResponseClass::sendResponse(
                TopupRequestResource::collection($topupRequests)->response()->getData(true),
                'Demandes de recharge récupérées avec succès'
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des demandes pour l'utilisateur $userId: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des demandes');
        }
    }

    /**
     * 📊 Récupérer les demandes par statut
     */
    public function findByStatus(string $status, Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $topupRequests = $this->topupRequestRepository->findByStatus($status, $perPage);

            return ApiResponseClass::sendResponse(
                TopupRequestResource::collection($topupRequests)->response()->getData(true),
                "Demandes $status récupérées avec succès"
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des demandes par statut $status: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des demandes');
        }
    }

    /**
     * Récupérer les recharges des pros pour le sous-admin connecté
     */
    public function getRechargesProForSubAdmin(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);

            // Récupérer l'utilisateur connecté (sous-admin)
            $subAdminId = Auth::guard()->user()->id;

            // Récupérer les recharges filtrées par statut et sous-admin
            $topupRequests = $this->topupRequestRepository->getRechargesProForSubAdmin($subAdminId, $perPage);

            return ApiResponseClass::sendResponse(
                TopupRequestResource::collection($topupRequests)->response()->getData(true),
                "Demandes récupérées avec succès"
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des demandes: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des demandes');
        }
    }

    /**
     * 📊 Récupérer les demandes par statut et par pro
     */

    public function findByStatusAndPro(string $status, string $proId,  Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $topupRequests = $this->topupRequestRepository->findByStatusAndPro($status, $proId, $perPage);

            return ApiResponseClass::sendResponse(
                TopupRequestResource::collection($topupRequests)->response()->getData(true),
                "Demandes $status récupérées avec succès"
            );
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des demandes par statut $status: " . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des demandes');
        }
    }

    /**
     * ✅ Mettre à jour le statut d'une demande (pour admin ou sous-admin)
     */
    public function updateStatus(UpdateStatusRequest $request, string $id): JsonResponse
    {
        try {
            $status = $request->input('status');
            $reason = $request->input('cancellation_reason');
            
            // ✅ FINTECH: toute approbation doit exiger statut_paiement
            if ($status === HelperStatus::APPROVED) {
                $validator = Validator::make($request->all(), [
                    'statut_paiement' => ['required', 'in:paye,impaye'],
                    'note' => ['nullable', 'string', 'max:500'],
                ]);

                if ($validator->fails()) {
                    return ApiResponseClass::sendError(
                        'Erreur de validation',
                        $validator->errors(),
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }

                $statutPaiement = $request->input('statut_paiement');
                $note = $request->input('note');
                $result = $this->topupRequestService->approveCommandeStrict($id, $statutPaiement, $note);

                if (!$result['success']) {
                    $statusCode = ($result['error'] ?? null) === 'server_error'
                        ? Response::HTTP_INTERNAL_SERVER_ERROR
                        : Response::HTTP_BAD_REQUEST;

                    return ApiResponseClass::sendError(
                        $result['message'],
                        ['error' => $result['error'] ?? null],
                        $statusCode
                    );
                }

                $updatedTopupRequest = $this->topupRequestRepository->getByID($id);
                return ApiResponseClass::sendResponse(
                    [
                        'commande' => new TopupRequestResource($updatedTopupRequest),
                        'creance' => $result['data']['creance'] ?? null,
                    ],
                    $result['message']
                );
            }

            // Traiter la demande via le service
            $result = $this->topupRequestService->processApproval($id, $status, $reason);

            if (!$result['success']) {
                return ApiResponseClass::sendError(
                    $result['message'],
                    ['error' => $result['error'] ?? ''],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Récupérer la demande mise à jour
             $updatedTopupRequest  = $this->topupRequestRepository->getByID($id);

            if (!$updatedTopupRequest) {
                return ApiResponseClass::notFound('Demande de recharge non trouvée');
            }

            // Notifier le demandeur par email quand la recharge est approuvée - best effort
            if ($status === HelperStatus::APPROVED) {
                try {
                    $updatedTopupRequest->loadMissing(['pro']);
                    $requester = $updatedTopupRequest->pro;
                    $approver = Auth::guard()->user();

                    if ($requester instanceof User && !empty($requester->email)) {
                        Mail::to($requester->email)->send(new TopupRequestApprovedMail($updatedTopupRequest, $requester, $approver));
                    }
                } catch (\Throwable $mailException) {
                    Log::error('Erreur envoi email (recharge approuvée): ' . $mailException->getMessage());
                }
            }

            return ApiResponseClass::sendResponse(
                new TopupRequestResource($updatedTopupRequest),
                $result['message']
            );

        } catch (\Exception $e) {
            Log::error("Erreur lors de la mise à jour du statut de la demande $id: " . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de la mise à jour du statut');
        }
    }


    /**
     * ✅ Mettre à jour le statut d'une demande (pour admin) ou sous admin
     */
    // public function updateStatus(UpdateStatusRequest $request, string $id): JsonResponse
    // {
    //     DB::beginTransaction();
    //     try {
    //         $topupRequest = $this->topupRequestRepository->getByID($id);

    //         if (!$topupRequest) {
    //             return ApiResponseClass::notFound('Demande de recharge non trouvée');
    //         }

    //         // ❌ Empêcher une approbation multiple
    //         if ($topupRequest->status === HelperStatus::APPROVED && $request->input('status') === HelperStatus::APPROVED) {
    //             return ApiResponseClass::sendError(
    //                 'Action non autorisée',
    //                 ['status' => 'Cette demande est déjà approuvée'],
    //                 Response::HTTP_FORBIDDEN
    //             );
    //         }

    //         $validator = Validator::make($request->all(), [
    //             'status' => ['required', 'in:' . implode(',', HelperStatus::getTopupRequestsStatuses())],
    //         ]);

    //         if ($validator->fails()) {
    //             return ApiResponseClass::sendError('Validation Error.', $validator->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
    //         }

    //         $status = $request->input('status');
    //         $reason = $request->input('cancellation_reason');

    //         $success = $this->topupRequestRepository->updateStatus($id, $status, $reason);

    //         if ($success) {
    //             // Si la demande est approuvée, transférer les fonds de l'admin vers le pro
    //             if ($status === HelperStatus::APPROVED) {
    //                 // ✅ Récupérer le wallet de l'admin connecté (celui qui valide)
    //                 $adminWallet = $this->walletService->getWalletByUserId(Auth::id());

    //                 if (!$adminWallet) {
    //                     DB::rollBack();
    //                     return ApiResponseClass::sendError(
    //                         'Wallet admin introuvable',
    //                         ['message' => 'Impossible de débiter le wallet de l\'administrateur'],
    //                         Response::HTTP_NOT_FOUND
    //                     );
    //                 }

    //                 // ✅ Vérifier que l'admin a suffisamment de solde
    //                 if ($adminWallet->cash_available < $topupRequest->amount) {
    //                     DB::rollBack();
    //                     return ApiResponseClass::sendError(
    //                         'Solde insuffisant',
    //                         [
    //                             'message' => 'Solde administrateur insuffisant pour approuver cette demande',
    //                             'admin_balance' => $adminWallet->cash_available,
    //                             'required_amount' => $topupRequest->amount
    //                         ],
    //                         Response::HTTP_FORBIDDEN
    //                     );
    //                 }

    //                 $provider = $topupRequest->kind;
    //                 $fromUser = Auth::guard()->user();

    //                 // ✅ CRÉDITER le wallet du pro (le bénéficiaire) en spécifiant l'admin comme source
    //                 // La méthode deposit se chargera automatiquement de débiter l'admin
    //                 $proWallet = $this->walletService->getWalletByUserId($topupRequest->pro_id);

    //                 if (!$proWallet) {
    //                     DB::rollBack();
    //                     return ApiResponseClass::sendError(
    //                         'Wallet pro introuvable',
    //                         ['message' => 'Impossible de créditer le wallet du professionnel'],
    //                         Response::HTTP_NOT_FOUND
    //                     );
    //                 }

    //                 // Au lieu de deposit, utiliser transfer :
    //                 $transferSuccess = $this->walletService->transfer(
    //                     $adminWallet->id,           // fromWalletId
    //                     $fromUser->id,                 // fromUserId  
    //                     $proWallet->id,             // toWalletId
    //                     $topupRequest->pro_id,      // toUserId
    //                     $topupRequest->amount,      // amount
    //                     $provider,                  // provider
    //                 );
    //                 if (!$transferSuccess) {
    //                     Log::error("Erreur lors du transfert pour la demande $topupRequest->idempotency_key");
    //                     DB::rollBack();
    //                     return ApiResponseClass::sendError(
    //                         'Erreur lors du transfert',
    //                         ['message' => 'Le statut a été mis à jour mais le transfert a échoué'],
    //                         Response::HTTP_INTERNAL_SERVER_ERROR
    //                     );
    //                 }
    //             }

    //             $updatedTopupRequest = $this->topupRequestRepository->getByID($id);
    //             DB::commit();

    //             return ApiResponseClass::sendResponse(
    //                 new TopupRequestResource($updatedTopupRequest),
    //                 "Statut de la demande mis à jour avec succès"
    //             );
    //         } else {
    //             DB::rollBack();
    //             return ApiResponseClass::sendError(
    //                 'Erreur lors de la mise à jour du statut',
    //                 ['message' => 'Impossible de mettre à jour le statut de la demande'],
    //                 Response::HTTP_INTERNAL_SERVER_ERROR
    //             );
    //         }
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error("Erreur lors de la mise à jour du statut de la demande $id: " . $e->getMessage());
    //         return ApiResponseClass::serverError($e, 'Erreur lors de la mise à jour du statut');
    //     }
    // }

    /**
     * ✅ Approuver une demande (shortcut pour admin)
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $actor = Auth::guard()->user();
        if (!$actor instanceof User || (! $actor->isSuperAdmin() && ! $actor->isSubAdmin())) {
            return ApiResponseClass::forbidden('Accès refusé. Action réservée aux administrateurs.');
        }

        $validator = Validator::make($request->all(), [
            'statut_paiement' => ['required', 'in:paye,impaye'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $statutPaiement = $request->input('statut_paiement');
        $note = $request->input('note');
        $result = $this->topupRequestService->approveCommandeStrict($id, $statutPaiement, $note);

        if (!$result['success']) {
            $statusCode = ($result['error'] ?? null) === 'server_error'
                ? Response::HTTP_INTERNAL_SERVER_ERROR
                : Response::HTTP_BAD_REQUEST;

            return ApiResponseClass::sendError(
                $result['message'],
                ['error' => $result['error'] ?? null],
                $statusCode
            );
        }

        $updatedTopupRequest = $this->topupRequestRepository->getByID($id);
        return ApiResponseClass::sendResponse(
            [
                'commande' => new TopupRequestResource($updatedTopupRequest),
                'creance' => $result['data']['creance'] ?? null,
            ],
            $result['message']
        );
    }

    /**
     * ❌ Rejeter une demande (shortcut pour admin)
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $request->merge(['status' => HelperStatus::REJECTED]);
        return $this->updateStatus(new UpdateStatusRequest($request->all()), $id);
    }

    /**
     * ⚠️ Annuler une demande (pour le pro ou admin)
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $topupRequest = $this->topupRequestRepository->getByID($id);

            if (!$topupRequest) {
                return ApiResponseClass::notFound('Demande de recharge non trouvée');
            }

            // ❌ Interdire l'annulation si déjà approuvée
            if ($topupRequest->status === HelperStatus::APPROVED) {
                return ApiResponseClass::sendError(
                    'Action non autorisée',
                    ['status' => 'Impossible d\'annuler une demande déjà approuvée'],
                    Response::HTTP_FORBIDDEN
                );
            }

            // ✅ Vérifier si la demande est annulable (ex: statut = PENDING)
            if (!$this->topupRequestRepository->canBeCancelled($id)) {
                return ApiResponseClass::sendError(
                    'Demande non annulable',
                    ['message' => 'Seules les demandes en attente peuvent être annulées'],
                    Response::HTTP_BAD_REQUEST
                );
            }


            // Vérifier les permissions
            // $user = auth()->user();
            // if ($topupRequest->pro_id !== $user->id && !$user->isAdmin()) {
            //     return ApiResponseClass::sendError(
            //         'Action non autorisée',
            //         ['message' => 'Vous ne pouvez pas annuler cette demande'],
            //         Response::HTTP_FORBIDDEN
            //     );
            // }

            // Vérifier si la demande peut être annulée
            if (!$this->topupRequestRepository->canBeCancelled($id)) {
                return ApiResponseClass::sendError(
                    'Demande non annulable',
                    ['message' => 'Seules les demandes en attente peuvent être annulées'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            $success = $this->topupRequestRepository->cancel($id, $request->input('reason'));

            if ($success) {
                DB::commit();
                return ApiResponseClass::sendResponse(
                    null,
                    'Demande annulée avec succès'
                );
            } else {
                DB::rollBack();
                return ApiResponseClass::sendError(
                    'Erreur lors de l\'annulation',
                    ['message' => 'Impossible d\'annuler la demande'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de l'annulation de la demande $id: " . $e->getMessage());
            return ApiResponseClass::serverError($e, 'Erreur lors de l\'annulation de la demande');
        }
    }

    /**
     * 📈 Récupérer les statistiques des demandes (pour admin)
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->topupRequestRepository->getStatistics();

            return ApiResponseClass::sendResponse(
                $stats,
                'Statistiques récupérées avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des statistiques: ' . $e->getMessage());
            return ApiResponseClass::serverError('Erreur lors de la récupération des statistiques');
        }
    }

    /**
     * Résout les destinataires admin pour les demandes de recharge.
     * Utilise l'email enregistré dans la table users (email de création de compte).
     * Priorité : sous-admin assigné au PRO > admins (hors sous-admin) capables de traiter les recharges.
     */
    private function resolveTopupAdminRecipients(User $requester): array
    {
        // Si le PRO est assigné à un sous-admin, notifier ce sous-admin.
        if (!empty($requester->assigned_user)) {
            $assigned = User::find($requester->assigned_user);
            if ($assigned instanceof User && !empty($assigned->email)) {
                return [$assigned->email];
            }
        }

        // Sinon, notifier les admins capables de traiter les recharges.
        // ⚠️ Les sous-admins doivent uniquement recevoir les emails des PRO qui leur sont assignés.
        // Donc: on exclut les rôles sous-admin du fallback (support/finance/commercial).
        return User::whereHas('role', function ($query) {
            $query
                ->where('is_super_admin', true)
                ->orWhere(function ($q2) {
                    $q2
                        ->whereNotIn('slug', [
                            RoleEnum::SUPPORT_ADMIN,
                            RoleEnum::FINANCE_ADMIN,
                            RoleEnum::COMMERCIAL_ADMIN,
                        ])
                        ->whereHas('permissions', function ($q) {
                            $q
                                ->whereIn('slug', ['transactions.validate_pending', 'finances.manual_credit_debit'])
                                ->whereIn('role_permissions.access_level', ['oui', 'limité']);
                        });
                });
        })
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
