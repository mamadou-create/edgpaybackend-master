<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Classes\ApiResponseClass;
use App\Enums\CommissionEnum;
use App\Enums\RoleEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\Wallet\WalletRequest;
use App\Http\Resources\WalletFloatResource;
use App\Interfaces\WalletRepositoryInterface;
use App\Mail\TransferSentMail;
use App\Mail\TransferReceivedMail;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class WalletController extends Controller
{
    private $walletRepository;
    private $walletService;

    public function __construct(
        WalletRepositoryInterface $walletRepository,
        WalletService $walletService
    ) {
        $this->walletRepository = $walletRepository;
        $this->walletService = $walletService;
    }

    private function getMaxClientWalletBalance(): int
    {
        $default = 1000000000;
        $setting = SystemSetting::where('key', 'max_client_wallet_balance')->first();

        if (!$setting) {
            return $default;
        }

        $raw = preg_replace('/[^\d]/', '', (string) $setting->value);
        $value = (int) ($raw === '' ? $default : $raw);

        return max(0, $value);
    }

    private function enforceClientWalletBalanceLimit(string $targetUserId, int $amount): ?\Illuminate\Http\JsonResponse
    {
        if ($amount <= 0) {
            return null;
        }

        $targetUser = User::with('role')->find($targetUserId);
        if (!$targetUser) {
            return ApiResponseClass::sendError(
                'Utilisateur destinataire introuvable.',
                [],
                Response::HTTP_NOT_FOUND
            );
        }

        $roleSlug = (string) ($targetUser->role?->slug ?? '');
        if ($roleSlug !== RoleEnum::CLIENT->value) {
            return null;
        }

        $targetWallet = $this->walletRepository->getByUserId($targetUserId);
        $currentBalance = (int) ($targetWallet->cash_available ?? 0);
        $maxClientWalletBalance = $this->getMaxClientWalletBalance();
        $projectedBalance = $currentBalance + $amount;

        if ($projectedBalance > $maxClientWalletBalance) {
            return ApiResponseClass::sendError(
                "Opération refusée: le solde du client dépasserait la limite autorisée ({$maxClientWalletBalance} GNF).",
                [
                    'client_current_balance' => $currentBalance,
                    'amount' => $amount,
                    'client_projected_balance' => $projectedBalance,
                    'max_client_wallet_balance' => $maxClientWalletBalance,
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return null;
    }

    public function index()
    {
        try {
            $wallets = $this->walletRepository->getAll();
            return ApiResponseClass::sendResponse(
                WalletResource::collection($wallets),
                'Wallets récupérés avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des wallets');
        }
    }

    public function store(WalletRequest $request)
    {
        DB::beginTransaction();
        try {
            Log::info('Store wallet called', ['user_id' => $request->user_id]);

            // Vérifie si l'utilisateur a déjà un wallet
            $existingWallet = $this->walletRepository->walletExistsForUser($request->user_id);

            if ($existingWallet) {
                Log::warning('Wallet déjà existant pour user', ['user_id' => $request->user_id]);
                return ApiResponseClass::sendError(
                    'Cet utilisateur possède déjà un wallet.',
                    409
                );
            }

            // Préparer les données
            $walletData = [
                'user_id' => $request->user_id,
                'currency' => $request->currency,
                'cash_available' => $request->cash_available ?? 0,
                'commission_available' => $request->commission_available ?? 0,
                'commission_balance' => $request->commission_balance ?? 0,
            ];

            Log::info('Données wallet préparées', $walletData);

            // Création du wallet
            $wallet = $this->walletRepository->create($walletData);

            if (!$wallet) {
                Log::error('Échec création wallet - méthode create() a retourné null');
                throw new \Exception('Échec de la création du wallet dans le repository');
            }

            DB::commit();

            Log::info('Wallet créé avec succès', ['wallet_id' => $wallet->id]);

            return ApiResponseClass::created(
                new WalletResource($wallet),
                'Wallet créé avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur WalletController@store: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());

            return ApiResponseClass::sendError(
                "Erreur lors de la création du wallet: " . $e->getMessage(),
                500
            );
        }
    }

    public function show($id)
    {
        try {
            $wallet = $this->walletRepository->getById($id);
            if (!$wallet) {
                return ApiResponseClass::notFound('Wallet introuvable');
            }

            return ApiResponseClass::sendResponse(
                new WalletResource($wallet),
                'Wallet récupéré avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération du wallet');
        }
    }

    public function getByUserId($userId)
    {
        try {
            $authorizedUserId = $this->resolveAuthorizedWalletUserId((string) $userId);
            if ($authorizedUserId instanceof \Illuminate\Http\JsonResponse) {
                return $authorizedUserId;
            }

            $wallet = $this->walletRepository->getByUserId($authorizedUserId);
            if (!$wallet) {
                return ApiResponseClass::notFound('Wallet introuvable');
            }

            return ApiResponseClass::sendResponse(
                new WalletResource($wallet),
                'Wallet récupéré avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération du wallet');
        }
    }

    public function showFloat(Request $request, string $walletId)
    {
        $request->validate([
            'provider' => 'required|string',
        ]);

        try {
            $float = $this->walletRepository->getFloatByWalletAndProvider($walletId, $request->provider);

            if (!$float) {
                return ApiResponseClass::notFound("Float introuvable pour le provider {$request->provider}");
            }

            return ApiResponseClass::sendResponse(new WalletFloatResource($float), "Float récupéré avec succès");
        } catch (\Exception $e) {
            return ApiResponseClass::rollback($e, "Erreur lors de la récupération du float");
        }
    }


    public function update(WalletRequest $request, $id)
    {
        DB::beginTransaction();
        try {
            $this->walletRepository->update($id, $request->validated());
            $wallet = $this->walletRepository->getById($id);
            DB::commit();

            return ApiResponseClass::sendResponse(
                new WalletResource($wallet),
                'Wallet mis à jour avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la mise à jour du wallet");
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $this->walletRepository->delete($id);
            DB::commit();

            return ApiResponseClass::sendResponse([], 'Wallet supprimé avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression du wallet");
        }
    }

    // Mise à jour du wallet flotte directement
    public function updateFloatRate(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'rate' => 'required',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $success = $this->walletRepository->updateFloatRate($id, $request->rate);

        if ($success) {
            return ApiResponseClass::sendResponse(
                null,
                'Flotte du wallet mise à jour avec succès'
            );
        }

        return ApiResponseClass::notFound("Flotte du wallet introuvable ou erreur lors de la mise à jour");
    }

    // Mise à jour du solde directement
    public function updateBalance(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $success = $this->walletRepository->updateBalance($id, $request->amount);

        if ($success) {
            $wallet = $this->walletRepository->getById($id);
            return ApiResponseClass::sendResponse(
                new WalletResource($wallet),
                'Balance mise à jour avec succès'
            );
        }

        return ApiResponseClass::notFound("Wallet introuvable ou erreur lors de la mise à jour");
    }

    // Ajout de commission
    // public function addCommission(Request $request, $id)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'commission' => 'required|integer|min:1',
    //     ]);

    //     if ($validator->fails()) {
    //         return ApiResponseClass::sendError(
    //             'Erreur de validation',
    //             $validator->errors(),
    //             Response::HTTP_UNPROCESSABLE_ENTITY
    //         );
    //     }

    //     $success = $this->walletRepository->addCommission($id, $request->commission);

    //     if ($success) {
    //         $wallet = $this->walletRepository->getById($id);
    //         return ApiResponseClass::sendResponse(
    //             new WalletResource($wallet),
    //             'Commission ajoutée avec succès'
    //         );
    //     }

    //     return ApiResponseClass::notFound("Wallet introuvable ou erreur lors de l'ajout de la commission");
    // }

    // ✅ Dépôt via WalletService
    public function deposit(Request $request, $walletId)
    {

        $validator = Validator::make($request->all(), [
            'amount'   => 'required|integer|min:1',
            'user_id'  => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Erreur de validation',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $limitError = $this->enforceClientWalletBalanceLimit(
                (string) $request->user_id,
                (int) $request->amount,
            );
            if ($limitError) {
                return $limitError;
            }

            $fromUser = Auth::guard()->user();

            $this->walletService->deposit(
                $walletId,
                $request->user_id,
                $request->amount,
                null,
                $fromUser->id,
            );

            return ApiResponseClass::sendResponse([], '💰 Dépôt effectué avec succès ');
        } catch (\Exception $e) {
            return ApiResponseClass::rollback($e, "Erreur lors du dépôt");
        }
    }

    // ✅ Retrait via WalletService
    public function withdraw(Request $request, $walletId)
    {
        $request->validate([
            'amount'   => 'required|integer|min:1',
            'user_id'  => 'required|exists:users,id',
        ]);

        try {

            $fromUser = Auth::guard()->user();

            $this->walletService->withdraw(
                $walletId,
                $request->user_id,
                $request->amount,
                null,
                $fromUser->id,
            );

            return ApiResponseClass::sendResponse([], '💸 Retrait effectué avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::rollback($e, "Erreur lors du retrait");
        }
    }

    /**
     * Transfert de commission vers le solde disponible
     * POST /api/wallets/{walletId}/transfer-commission
     */
    public function transferCommission(Request $request, $walletId)
    {
        $request->validate([
            'amount' => 'required|integer|min:1',
            'user_id' => 'required|uuid|exists:users,id'
        ]);

        DB::beginTransaction();

        try {
            // // Vérifier que l'utilisateur authentifié correspond à l'user_id fourni
            // $authenticatedUser = Auth::guard()->user();
            // if ($authenticatedUser->id !== $request->user_id) {
            //     return ApiResponseClass::sendError('Action non autorisée pour cet utilisateur', 403);
            // }

            // // Vérifier l'existence du wallet
            // $wallet = $this->walletRepository->getById($walletId);
            // if (!$wallet) {
            //     return ApiResponseClass::sendError('Wallet non trouvé', 404);
            // }

            // // Vérifier que l'utilisateur est bien propriétaire du wallet
            // if ($wallet->user_id !== $request->user_id) {
            //     return ApiResponseClass::sendError('Accès non autorisé à ce wallet', 403);
            // }

            // // Vérifier que le montant est valide
            // if ($request->amount <= 0) {
            //     return ApiResponseClass::sendError('Le montant doit être supérieur à 0', 400);
            // }

            // Effectuer le transfert
            $success = $this->walletService->transferCommission(
                $walletId,
                $request->user_id,
                $request->amount
            );

            if ($success) {
                DB::commit();

                // Récupérer le wallet mis à jour pour la réponse
                $updatedWallet = $this->walletRepository->getById($walletId);

                return ApiResponseClass::sendResponse([
                    'wallet' => [
                        'id' => $updatedWallet->id,
                        'commission_available' => $updatedWallet->commission_available,
                        'cash_available' => $updatedWallet->cash_available,
                        'total_balance' => $updatedWallet->cash_available + $updatedWallet->commission_available
                    ],
                    'transfer' => [
                        'amount' => $request->amount,
                        'timestamp' => now()->toISOString()
                    ]
                ], 'Commission transférée vers le solde avec succès');
            } else {
                DB::rollBack();
                return ApiResponseClass::sendError('Échec du transfert de commission. Vérifiez les soldes disponibles.', 400);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            logger()->error("Erreur contrôleur transferCommission: " . $e->getMessage(), [
                'wallet_id' => $walletId,
                'user_id' => $request->user_id,
                'amount' => $request->amount
            ]);

            return ApiResponseClass::rollback($e, "Erreur lors du transfert de commission: " . $e->getMessage());
        }
    }

    // public function addCommission(Request $request, string $walletId)
    // {
    //     try {
    //         $validated = $request->validate([
    //             'commission' => 'required|numeric|min:1',
    //             'provider'   => 'required|string',
    //         ]);

    //         // 🔒 Récupération du wallet pour identifier le userId
    //         $wallet = $this->walletRepository->getById($walletId);
    //         if (!$wallet) {
    //             return ApiResponseClass::sendError(
    //                 'Wallet introuvable',
    //                 null,
    //                 404
    //             );
    //         }

    //         $userId = $wallet->user_id;

    //         // ⚡ Ajout de la commission
    //         $success = $this->walletService->addCommission(
    //             $walletId,
    //             $userId,
    //             $validated['commission'],
    //             $validated['provider']
    //         );

    //         if (!$success) {
    //             return ApiResponseClass::sendError(
    //                 'Erreur lors de l’ajout de la commission',
    //                 null,
    //                 500
    //             );
    //         }

    //         return ApiResponseClass::sendResponse(
    //             null,
    //             'Commission ajoutée avec succès'
    //         );
    //     } catch (\Exception $e) {
    //         return ApiResponseClass::throw($e, 'Erreur lors de l’ajout de la commission');
    //     }
    // }



    public function credit(Request $request)
    {
        $request->validate([
            'to_user_id' => 'required|uuid|exists:users,id',
            'amount'     => 'required|numeric|min:1',
        ]);

        try {
            $fromUser = Auth::guard()->user();

            $this->walletService->creditWallet(
                $fromUser->id,
                $request->to_user_id,
                (int)$request->amount
            );

            // Notification mail aux deux parties
            $toUser = User::find($request->to_user_id);
            $amount = (int) $request->amount;

            try {
                if (!empty($fromUser->email)) {
                    Mail::to($fromUser->email)->send(new TransferSentMail($fromUser, $toUser, $amount));
                }
            } catch (\Throwable $e) {
                Log::error('Erreur envoi TransferSentMail (credit): ' . $e->getMessage());
            }

            try {
                if ($toUser && !empty($toUser->email)) {
                    Mail::to($toUser->email)->send(new TransferReceivedMail($fromUser, $toUser, $amount));
                }
            } catch (\Throwable $e) {
                Log::error('Erreur envoi TransferReceivedMail (credit): ' . $e->getMessage());
            }

            return ApiResponseClass::sendResponse(null, 'Crédit effectué avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::throw($e, 'Erreur lors du crédit du wallet');
        }
    }

    public function rechargeSuperAdmin(Request $request)
    {
        $request->validate([
            'wallet_id' => 'required|string',
            'amount' => 'required|integer|min:1',
            'description' => 'nullable|string'
        ]);

        $user = Auth::guard()->user();

        // Vérification simple que l'utilisateur est un Super Admin
        if (!$user->isSuperAdmin) {
            return ApiResponseClass::sendError(
                'Accès non autorisé',
                'Seuls les Super Admins peuvent effectuer cette action',
                403
            );
        }

        if (!$user->wallet) {
            return ApiResponseClass::notFound('Wallet super admin introuvable');
        }

        try {
            $this->walletRepository->rechargeSuperAdmin(
                $user->wallet->id,
                $request->amount,
                null
            );

            return ApiResponseClass::sendResponse(null, 'Recharge effectuée avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::throw($e, 'Erreur lors de la recharge');
        }
    }

    /**
     * Recharge un PRO par un sous-admin
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function rechargeProBySubAdmin(Request $request)
    {
        $request->validate([
            'pro_id' => 'required|string|exists:users,id',
            'amount' => 'required|integer|min:1',
            'description' => 'nullable|string|max:255'
        ]);

        try {
            // Récupérer l'ID du sous-admin connecté
            $subAdminUserId = Auth::id();

            // Appeler la méthode de service
            $result = $this->walletService->rechargeProBySubAdmin(
                $subAdminUserId,
                $request->pro_id,
                $request->amount,
                CommissionEnum::SOUS_ADMIN,
                $request->description
            );

            if ($result) {
                // Notification mail aux deux parties
                $subAdmin = User::find($subAdminUserId);
                $proUser  = User::find($request->pro_id);
                $amount   = (int) $request->amount;

                try {
                    if ($subAdmin && !empty($subAdmin->email)) {
                        Mail::to($subAdmin->email)->send(new TransferSentMail($subAdmin, $proUser, $amount));
                    }
                } catch (\Throwable $e) {
                    Log::error('Erreur envoi TransferSentMail (rechargeProBySubAdmin): ' . $e->getMessage());
                }

                try {
                    if ($proUser && !empty($proUser->email)) {
                        Mail::to($proUser->email)->send(new TransferReceivedMail($subAdmin, $proUser, $amount));
                    }
                } catch (\Throwable $e) {
                    Log::error('Erreur envoi TransferReceivedMail (rechargeProBySubAdmin): ' . $e->getMessage());
                }

                return ApiResponseClass::sendResponse(
                    [
                        'pro_user_id' => $request->pro_id,
                        'amount' => $request->amount,
                        'provider' => CommissionEnum::SOUS_ADMIN,
                        'commission_paid' => true
                    ],
                    'Recharge effectuée avec succès'
                );
            }

            return ApiResponseClass::throw(
                new \Exception("La recharge a échoué"),
                'Erreur lors de la recharge',
                500
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recharge PRO: ' . $e->getMessage(), [
                'sub_admin_id' => Auth::id(),
                'request_data' => $request->all()
            ]);

            return ApiResponseClass::throw($e, 'Erreur lors de la recharge');
        }
    }


    /**
     * ✅ Transférer entre deux floats par leurs IDs
     * POST /api/wallets/{walletId}/transfer-between-floats
     */
    public function transferBetweenFloats(Request $request, string $walletId)
    {
        $request->validate([
            'from_float_id' => 'required|uuid|exists:wallet_floats,id',
            'to_float_id'   => 'required|uuid|exists:wallet_floats,id',
            'amount'        => 'required|integer|min:1',
            'description'   => 'nullable|string|max:255'
        ]);

        try {
            $result = $this->walletService->transferBetweenFloats(
                $walletId,
                $request->from_float_id,
                $request->to_float_id,
                $request->amount,
                $request->description
            );

            if ($result['success']) {
                return ApiResponseClass::sendResponse(
                    $result['data'],
                    $result['message']
                );
            }

            return ApiResponseClass::sendError(
                $result['message'],
                $result['error'] ?? null,
                400
            );
        } catch (\Exception $e) {
            Log::error('Erreur transferBetweenFloats', [
                'wallet_id' => $walletId,
                'from_float_id' => $request->from_float_id,
                'to_float_id' => $request->to_float_id,
                'amount' => $request->amount,
                'error' => $e->getMessage()
            ]);

            return ApiResponseClass::sendError(
                'Erreur lors du transfert entre floats: ' . $e->getMessage(),
                null,
                500
            );
        }
    }

    /**
     * ✅ Transférer entre deux floats par leurs providers
     * POST /api/wallets/{walletId}/transfer-between-providers
     */
    public function transferBetweenProviders(Request $request, string $walletId)
    {
        $request->validate([
            'from_provider' => 'required|string|in:EDG,GSS',
            'to_provider'   => 'required|string|in:EDG,GSS',
            'amount'        => 'required|integer|min:1',
            'description'   => 'nullable|string|max:255'
        ]);

        try {
            $result = $this->walletService->transferBetweenFloatProviders(
                $walletId,
                $request->from_provider,
                $request->to_provider,
                $request->amount,
                $request->description
            );

            if ($result['success']) {
                return ApiResponseClass::sendResponse(
                    $result['data'],
                    $result['message']
                );
            }

            return ApiResponseClass::sendError(
                $result['message'],
                $result['error'] ?? null,
                400
            );
        } catch (\Exception $e) {
            Log::error('Erreur transferBetweenProviders', [
                'wallet_id' => $walletId,
                'from_provider' => $request->from_provider,
                'to_provider' => $request->to_provider,
                'amount' => $request->amount,
                'error' => $e->getMessage()
            ]);

            return ApiResponseClass::sendError(
                'Erreur lors du transfert entre providers: ' . $e->getMessage(),
                null,
                500
            );
        }
    }


    /**
     * GET /wallets/{userId}/stats
     */
    public function getUserStats($userId)
    {
        try {
            $authorizedUserId = $this->resolveAuthorizedWalletUserId((string) $userId);
            if ($authorizedUserId instanceof \Illuminate\Http\JsonResponse) {
                return $authorizedUserId;
            }

            $stats = $this->walletRepository->getUserStats($authorizedUserId);
            return ApiResponseClass::sendResponse($stats, 'Statistiques du wallet récupérées avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::throw($e, 'Erreur lors de la récupération des statistiques du wallet');
        }
    }

    private function resolveAuthorizedWalletUserId(string $requestedUserId): string|\Illuminate\Http\JsonResponse
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
            Log::warning('Tentative d\'accès à un wallet d\'un autre utilisateur', [
                'authenticated_user_id' => $authenticatedUser->id,
                'requested_user_id' => $requestedUserId,
            ]);

            return ApiResponseClass::forbidden('Vous ne pouvez consulter que votre propre wallet');
        }

        return (string) $authenticatedUser->id;
    }


    /**
     * Récupère le solde cohérent de l'utilisateur
     */
    public function getConsistentBalance()
    {
        try {
            $userId = Auth::id();
            $balanceData = $this->walletRepository->getConsistentBalance($userId);

            return ApiResponseClass::sendResponse($balanceData, 'Solde cohérent récupéré avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur getConsistentBalance', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return ApiResponseClass::sendError(
                'Erreur lors de la récupération du solde',
                $e->getMessage(),
                500
            );
        }
    }

    /**
     * Récupère uniquement le solde disponible
     */
    public function getAvailableBalance()
    {
        try {
            $userId = Auth::id();
            $balance = $this->walletRepository->getAvailableBalance($userId);

            return ApiResponseClass::sendResponse([
                'user_id' => $userId,
                'available_balance' => $balance,
                'formatted_balance' => number_format($balance, 0, ',', ' ') . ' GNF'
            ], 'Solde disponible récupéré avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur getAvailableBalance', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return ApiResponseClass::sendError(
                'Erreur lors de la récupération du solde',
                null,
                500
            );
        }
    }

    /**
     * Vérifie si le solde est suffisant pour une transaction
     */
    public function checkSufficientBalance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données invalides',
                $validator->errors(),
                422
            );
        }

        try {
            $userId = Auth::id();
            $amount = $request->input('amount');

            $balanceCheck = $this->walletRepository->hasSufficientBalance($userId, $amount);

            return ApiResponseClass::sendResponse($balanceCheck, 'Vérification du solde effectuée avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur checkSufficientBalance', [
                'user_id' => Auth::id(),
                'amount' => $request->input('amount'),
                'error' => $e->getMessage()
            ]);

            return ApiResponseClass::sendError(
                'Erreur lors de la vérification du solde',
                null,
                500
            );
        }
    }

    /**
     * Récupère le solde de l'utilisateur
     */
    public function getAllBalances()
    {
        try {
            $userId = Auth::id();
            $balanceData = $this->walletRepository->getConsistentBalance($userId);

            if (!$balanceData['success']) {
                return ApiResponseClass::sendError(
                    $balanceData['error'] ?? 'Erreur lors de la récupération du solde',
                    null,
                    400
                );
            }

            return ApiResponseClass::sendResponse([
                'user_id' => $userId,
                'balance' => [
                    'available' => $balanceData['wallet']['cash_available'],
                    'blocked' => $balanceData['wallet']['blocked_amount'],
                    'net' => $balanceData['wallet']['net_balance'],
                    'commission' => $balanceData['wallet']['commission_available'],
                ],
                'formatted' => [
                    'available' => number_format($balanceData['wallet']['cash_available'], 0, ',', ' ') . ' GNF',
                    'blocked' => number_format($balanceData['wallet']['blocked_amount'], 0, ',', ' ') . ' GNF',
                    'net' => number_format($balanceData['wallet']['net_balance'], 0, ',', ' ') . ' GNF',
                    'commission' => number_format($balanceData['wallet']['commission_available'], 0, ',', ' ') . ' GNF',
                ],
                'summary' => [
                    'available_balance' => $balanceData['summary']['available_balance'],
                    'formatted_balance' => $balanceData['summary']['formatted_balance'],
                ],
                'timestamp' => now()->toISOString()
            ], 'Solde récupéré avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur getBalance', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return ApiResponseClass::sendError(
                'Erreur lors de la récupération du solde',
                null,
                500
            );
        }
    }


    /**
     * Endpoint spécifique pour Pro (utilisé dans l'interface Dart)
     */
    public function getEdgProBalance()
    {
        try {
            $userId = Auth::id();
            $balanceData = $this->walletRepository->getConsistentBalance($userId);

            return ApiResponseClass::sendResponse($balanceData, 'Solde Pro récupéré avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur getProBalance', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return ApiResponseClass::sendError(
                'Erreur lors de la récupération du solde',
                null,
                500
            );
        }
    }

    /**
     * Récupère le total de tous les cash_available des wallets
     */
    public function getTotalCash()
    {
        try {
            $totalCash = $this->walletRepository->getTotalCashAvailable();

            return ApiResponseClass::sendResponse([
                'total_cash_available' => $totalCash,
                'formatted_total' => number_format($totalCash, 0, ',', ' ') . ' GNF'
            ], 'Solde en détention récupéré avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::sendError(
                'Erreur lors de la récupération du solde',
                null,
                500
            );
        }
    }

    /**
     * API pour récupérer le résumé des commissions
     */
    public function getCommissionSummary(Request $request)
    {
        try {
            // Récupérer uniquement la devise si spécifiée
            $currency = $request->get('currency'); // Devise optionnelle

            // Appeler la méthode du repository (pas besoin de passer les rôles)
            $summary = $this->walletRepository->getCommissionSummary($currency);

            return ApiResponseClass::sendResponse($summary, 'Commissions récupérées avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur API getCommissionSummary: ' . $e->getMessage());

            return ApiResponseClass::sendError(
                'Erreur lors de la récupération des commissions',
                null,
                500
            );
        }
    }
}
