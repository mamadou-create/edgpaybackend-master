<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Interfaces\DjomyServiceInterface;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\DmlService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Contrôleur dédié au wallet du client simple.
 *
 * Endpoints:
 *   GET  /client/wallet/balance           → solde du wallet
 *   POST /client/wallet/topup/initiate    → initier une recharge via Djomy
 *   POST /client/wallet/topup/confirm     → confirmer et créditer le wallet
 *   POST /client/wallet/transfer          → transfert wallet client → client
 *   POST /client/wallet/cashout-pro       → retrait cash client chez un PRO
 *   POST /pro/wallet/recharge-client      → recharge client par un PRO
 */
class ClientWalletController extends Controller
{
    public function __construct(
        private DjomyServiceInterface $djomyService,
        private WalletService $walletService,
        private DmlService $dmlService,
    ) {}

    // ──────────────────────────────────────────────────────────────
    // Helper — récupère le super administrateur
    // ──────────────────────────────────────────────────────────────
    private function getSuperAdmin(): User
    {
        $superAdmin = User::whereHas('role', fn ($q) =>
            $q->where('slug', RoleEnum::SUPER_ADMIN)
        )->first();

        if (!$superAdmin) {
            throw new \RuntimeException('Super administrateur introuvable.');
        }

        return $superAdmin;
    }

    private function getOrCreatePercentSetting(
        string $key,
        string $description,
        int $order,
        float $default = 0.0
    ): float {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            $setting = SystemSetting::create([
                'key'         => $key,
                'value'       => (string) $default,
                'type'        => 'float',
                'group'       => 'payments',
                'description' => $description,
                'is_active'   => true,
                'is_editable' => true,
                'order'       => $order,
            ]);
        }

        $raw = str_replace(',', '.', (string) ($setting->value ?? $default));
        $percent = (float) $raw;

        if ($percent < 0) {
            return 0.0;
        }

        if ($percent > 100) {
            return 100.0;
        }

        return $percent;
    }

    private function getOrCreateIntegerSetting(
        string $key,
        string $description,
        int $order,
        int $default = 1000000000
    ): int {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            $setting = SystemSetting::create([
                'key'         => $key,
                'value'       => (string) $default,
                'type'        => 'integer',
                'group'       => 'limits',
                'description' => $description,
                'is_active'   => true,
                'is_editable' => true,
                'order'       => $order,
            ]);
        }

        $raw = preg_replace('/[^\d]/', '', (string) ($setting->value ?? $default));
        $value = (int) ($raw === '' ? $default : $raw);

        return max(0, $value);
    }

    private function creditProCommission(
        User $pro,
        int $baseAmount,
        float $percent,
        string $reason,
        bool $debitFromSuperAdminCash = false
    ): int
    {
        if ($percent <= 0 || $baseAmount <= 0) {
            return 0;
        }

        $commission = (int) round(($baseAmount * $percent) / 100);
        if ($commission <= 0) {
            return 0;
        }

        if ($debitFromSuperAdminCash) {
            $superAdmin = $this->getSuperAdmin();

            try {
                $superAdminWalletBase = $this->walletService->getWalletByUserId($superAdmin->id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $created = $this->walletService->createWalletForUser($superAdmin->id);
                $superAdminWalletBase = $created['wallet'];
            }

            $superAdminWallet = Wallet::where('id', $superAdminWalletBase->id)
                ->lockForUpdate()
                ->first();

            if (!$superAdminWallet) {
                throw new \RuntimeException('Wallet super admin introuvable pour financer la commission PRO.');
            }

            $availableSuperAdmin = (int) $superAdminWallet->cash_available - (int) $superAdminWallet->blocked_amount;
            if ($availableSuperAdmin < $commission) {
                throw new \RuntimeException(
                    "Solde super admin insuffisant pour financer la commission PRO. Disponible: {$availableSuperAdmin}, requis: {$commission}"
                );
            }

            $superAdminWallet->cash_available = (int) $superAdminWallet->cash_available - $commission;
            $superAdminWallet->save();

            $superAdmin->solde_portefeuille = max(0, (int) $superAdmin->solde_portefeuille - $commission);
            $superAdmin->save();

            Log::info('[ClientWalletController] commission PRO financée par super admin', [
                'super_admin_id' => $superAdmin->id,
                'commission'     => $commission,
                'reason'         => $reason,
            ]);
        }

        try {
            $proWalletBase = $this->walletService->getWalletByUserId($pro->id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $created = $this->walletService->createWalletForUser($pro->id);
            $proWalletBase = $created['wallet'];
        }

        $proWallet = Wallet::where('id', $proWalletBase->id)
            ->lockForUpdate()
            ->first();

        if (!$proWallet) {
            throw new \RuntimeException('Wallet PRO introuvable pour crédit commission.');
        }

        $proWallet->commission_available = (int) $proWallet->commission_available + $commission;
        $proWallet->commission_balance = (int) $proWallet->commission_balance + $commission;
        $proWallet->save();

        $pro->commission_portefeuille = (int) $pro->commission_portefeuille + $commission;
        $pro->save();

        Log::info('[ClientWalletController] commission PRO créditée', [
            'pro_id'     => $pro->id,
            'base'       => $baseAmount,
            'percent'    => $percent,
            'commission' => $commission,
            'reason'     => $reason,
        ]);

        return $commission;
    }

    // ──────────────────────────────────────────────────────────────
    // GET /client/wallet/balance
    // ──────────────────────────────────────────────────────────────
    public function balance(): JsonResponse
    {
        try {
            $user = Auth::user();
            $wallet = $this->walletService->getWalletByUserId($user->id);

            return ApiResponseClass::sendResponse([
                'wallet_id'         => $wallet->id,
                'cash_available'    => $wallet->cash_available,
                'currency'          => $wallet->currency ?? 'GNF',
            ], 'Solde récupéré avec succès');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // Wallet n'existe pas encore – on en crée un automatiquement
            try {
                $user   = Auth::user();
                $result = $this->walletService->createWalletForUser($user->id);
                $wallet = $result['wallet'];

                return ApiResponseClass::sendResponse([
                    'wallet_id'      => $wallet->id,
                    'cash_available' => 0,
                    'currency'       => 'GNF',
                ], 'Wallet créé et solde récupéré');
            } catch (\Exception $e) {
                Log::error('[ClientWalletController] balance error', ['error' => $e->getMessage()]);
                return ApiResponseClass::serverError('Impossible de récupérer le solde du wallet');
            }
        } catch (\Exception $e) {
            Log::error('[ClientWalletController] balance error', ['error' => $e->getMessage()]);
            return ApiResponseClass::serverError('Impossible de récupérer le solde du wallet');
        }
    }

    // ──────────────────────────────────────────────────────────────
    // POST /client/wallet/topup/initiate
    // Body: { amount, country_code, payer_number? }
    // ──────────────────────────────────────────────────────────────
    public function initiateTopup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount'        => 'required|numeric|min:20000',
            'country_code'  => 'required|string|size:2',
            'payer_number'  => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de recharge invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $user   = Auth::user();
            $amount = (int) $request->amount;

            // S'assurer que le wallet existe
            try {
                $this->walletService->getWalletByUserId($user->id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->walletService->createWalletForUser($user->id);
            }

            $payload = [
                'amount'      => $amount,
                'countryCode' => $request->country_code,
                'description' => "Recharge wallet client - {$user->name}",
                'serviceType' => 'wallet_topup',
                'compteurId'  => 'WALLET_' . $user->id,
            ];

            if ($request->filled('payer_number')) {
                $payload['payerNumber'] = $request->payer_number;
            }

            $result = $this->djomyService->createPaymentWithGateway($payload);

            if (!$result['success']) {
                return ApiResponseClass::sendError(
                    $result['message'] ?? 'Erreur lors de l\'initiation de la recharge.',
                    $result['data'] ?? [],
                    $result['status'] ?? Response::HTTP_BAD_REQUEST
                );
            }

            return ApiResponseClass::sendResponse([
                'transaction_id' => $result['data']['transactionId']  ?? null,
                'redirect_url'   => $result['data']['redirectUrl']    ?? null,
                'gateway_url'    => $result['data']['gatewayUrl']     ?? $result['data']['redirectUrl'] ?? null,
                'amount'         => $amount,
            ], 'Recharge initiée avec succès');
        } catch (\Exception $e) {
            Log::error('[ClientWalletController] initiateTopup error', ['error' => $e->getMessage()]);
            return ApiResponseClass::serverError('Erreur lors de l\'initiation de la recharge');
        }
    }

    // ──────────────────────────────────────────────────────────────
    // POST /client/wallet/topup/confirm
    // Body: { transaction_id }
    // ──────────────────────────────────────────────────────────────
    public function confirmTopup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'transaction_id manquant.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $transactionId = $request->transaction_id;

        try {
            $user = Auth::user();

            // ─── Idempotence : vérifier si déjà traité ───────────────
            $localPayment = Payment::where('transaction_id', $transactionId)
                ->where('user_id', $user->id)
                ->first();

            if ($localPayment && $localPayment->processed_at !== null) {
                // Déjà crédité – retourner le solde actuel
                $wallet = $this->walletService->getWalletByUserId($user->id);
                return ApiResponseClass::sendResponse([
                    'status'         => 'already_credited',
                    'message'        => 'Ce paiement a déjà été traité.',
                    'cash_available' => $wallet->cash_available,
                ], 'Paiement déjà traité');
            }

            // ─── Vérification du statut côté Djomy ───────────────────
            $statusResult = $this->djomyService->getPaymentStatus($transactionId);

            if (!$statusResult['success']) {
                return ApiResponseClass::sendError(
                    $statusResult['message'] ?? 'Impossible de vérifier le statut du paiement.',
                    [],
                    Response::HTTP_BAD_GATEWAY
                );
            }

            $djomyStatus = strtoupper(
                $statusResult['data']['status']
                ?? $statusResult['data']['paymentStatus']
                ?? 'PENDING'
            );

            if ($djomyStatus !== 'SUCCESS') {
                return ApiResponseClass::sendResponse([
                    'status'  => strtolower($djomyStatus),
                    'message' => match ($djomyStatus) {
                        'PENDING', 'PROCESSING' => 'Paiement en attente de confirmation.',
                        'FAILED'                => 'Le paiement a échoué.',
                        'CANCELLED'             => 'Le paiement a été annulé.',
                        'EXPIRED'               => 'Le paiement a expiré.',
                        default                 => 'Statut inconnu.',
                    },
                ], 'Statut du paiement récupéré');
            }

            // ─── Crédit du wallet (débit super admin → crédit client) ─
            $amount = (int) (
                $statusResult['data']['amount']
                ?? $localPayment?->amount
                ?? 0
            );

            if ($amount <= 0 && $localPayment) {
                $amount = (int) $localPayment->amount;
            }

            if ($amount <= 0) {
                Log::error('[ClientWalletController] confirmTopup: montant invalide', [
                    'transaction_id' => $transactionId,
                    'user_id'        => $user->id,
                ]);
                return ApiResponseClass::serverError('Montant du paiement Djomy invalide ou nul.');
            }

            DB::beginTransaction();
            try {
                // 🔑 Récupérer le super admin (source de liquidité)
                $superAdmin   = $this->getSuperAdmin();
                $clientWallet = $this->walletService->getWalletByUserId($user->id);

                $maxClientWalletBalance = $this->getOrCreateIntegerSetting(
                    key: 'max_client_wallet_balance',
                    description: 'Solde maximum autorisé pour un wallet client',
                    order: 21,
                    default: 1000000000
                );

                $projectedBalance = (int) $clientWallet->cash_available + $amount;
                if ($projectedBalance > $maxClientWalletBalance) {
                    DB::rollBack();
                    return ApiResponseClass::sendError(
                        "Opération refusée: le solde client dépasserait la limite autorisée ({$maxClientWalletBalance} GNF).",
                        [
                            'current_balance' => (int) $clientWallet->cash_available,
                            'amount' => $amount,
                            'projected_balance' => $projectedBalance,
                            'max_client_wallet_balance' => $maxClientWalletBalance,
                        ],
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }

                // Transfert : super admin → client
                // deposit() avec fromUserId débite le super admin ET crédite le client
                $deposited = $this->walletService->deposit(
                    walletId:    $clientWallet->id,
                    userId:      $user->id,
                    amount:      $amount,
                    description: "Recharge wallet client via Djomy (ref: {$transactionId})",
                    fromUserId:  $superAdmin->id,
                );

                if (!$deposited) {
                    DB::rollBack();
                    return ApiResponseClass::serverError('Échec du transfert super admin → client');
                }

                // alias pour la suite
                $wallet = $clientWallet;

                // Marquer le paiement local comme traité
                if ($localPayment) {
                    $localPayment->processed_at = now();
                    $localPayment->save();
                }

                DB::commit();

                // Recharger le wallet pour retourner le nouveau solde
                $wallet->refresh();

                Log::info('[ClientWalletController] confirmTopup: transfert super_admin→client réussi', [
                    'super_admin_id' => $superAdmin->id,
                    'client_id'      => $user->id,
                    'amount'         => $amount,
                    'new_balance'    => $wallet->cash_available,
                ]);

                return ApiResponseClass::sendResponse([
                    'status'          => 'success',
                    'amount_credited' => $amount,
                    'cash_available'  => $wallet->cash_available,
                    'currency'        => $wallet->currency ?? 'GNF',
                ], 'Wallet rechargé avec succès');
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('[ClientWalletController] confirmTopup credit error', [
                    'error'          => $e->getMessage(),
                    'transaction_id' => $transactionId,
                    'user_id'        => $user->id,
                ]);
                return ApiResponseClass::serverError('Erreur lors du crédit du wallet: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            Log::error('[ClientWalletController] confirmTopup error', ['error' => $e->getMessage()]);
            return ApiResponseClass::serverError('Erreur lors de la confirmation de la recharge');
        }
    }

    // ──────────────────────────────────────────────────────────────
    // POST /client/wallet/transfer
    // Body: { amount, recipient_phone }
    // ──────────────────────────────────────────────────────────────
    public function transferToClient(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount'          => 'required|numeric|min:1000',
            'recipient_phone' => 'required|string|min:6|max:20',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de transfert invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $sender = Auth::user();
            $amount = (int) $request->input('amount');
            $recipientPhone = trim((string) $request->input('recipient_phone'));

            $recipient = User::where('phone', $recipientPhone)
                ->whereHas('role', fn ($q) => $q->where('slug', RoleEnum::CLIENT->value))
                ->first();

            if (!$recipient) {
                return ApiResponseClass::sendError(
                    'Aucun client trouvé avec ce numéro.',
                    [],
                    Response::HTTP_NOT_FOUND
                );
            }

            if ($recipient->id === $sender->id) {
                return ApiResponseClass::sendError(
                    'Vous ne pouvez pas vous transférer de l\'argent à vous-même.',
                    [],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $senderWallet = $this->walletService->getWalletByUserId($sender->id);

            try {
                $recipientWallet = $this->walletService->getWalletByUserId($recipient->id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $created = $this->walletService->createWalletForUser($recipient->id);
                $recipientWallet = $created['wallet'];
            }

            $maxClientWalletBalance = $this->getOrCreateIntegerSetting(
                key: 'max_client_wallet_balance',
                description: 'Solde maximum autorisé pour un wallet client',
                order: 21,
                default: 1000000000
            );
            $projectedRecipientBalance = (int) $recipientWallet->cash_available + $amount;
            if ($projectedRecipientBalance > $maxClientWalletBalance) {
                return ApiResponseClass::sendError(
                    "Opération refusée: le solde du client destinataire dépasserait la limite autorisée ({$maxClientWalletBalance} GNF).",
                    [
                        'recipient_current_balance' => (int) $recipientWallet->cash_available,
                        'amount' => $amount,
                        'recipient_projected_balance' => $projectedRecipientBalance,
                        'max_client_wallet_balance' => $maxClientWalletBalance,
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $available = (int) ($senderWallet->cash_available - $senderWallet->blocked_amount);
            if ($available < $amount) {
                return ApiResponseClass::sendError(
                    "Solde insuffisant. Disponible: {$available} GNF, requis: {$amount} GNF",
                    ['available' => $available, 'required' => $amount],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $ok = $this->walletService->transfer(
                fromWalletId: $senderWallet->id,
                fromUserId: $sender->id,
                toWalletId: $recipientWallet->id,
                toUserId: $recipient->id,
                amount: $amount,
                description: "Transfert wallet vers {$recipient->display_name} ({$recipient->phone})",
            );

            if (!$ok) {
                return ApiResponseClass::serverError('Échec du transfert wallet.');
            }

            $senderWallet->refresh();

            return ApiResponseClass::sendResponse([
                'amount'            => $amount,
                'recipient'         => [
                    'id'           => $recipient->id,
                    'display_name' => $recipient->display_name,
                    'phone'        => $recipient->phone,
                ],
                'new_balance'       => $senderWallet->cash_available,
                'currency'          => $senderWallet->currency ?? 'GNF',
            ], 'Transfert effectué avec succès');
        } catch (\Exception $e) {
            Log::error('[ClientWalletController] transferToClient error', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return ApiResponseClass::serverError('Erreur lors du transfert wallet.');
        }
    }

    // ──────────────────────────────────────────────────────────────
    // POST /client/wallet/cashout-pro
    // Body: { amount, pro_phone }
    // ──────────────────────────────────────────────────────────────
    public function cashoutAtPro(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount'    => 'required|numeric|min:1000',
            'pro_phone' => 'required|string|min:6|max:20',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de retrait invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $client = Auth::user();
            $amount = (int) $request->input('amount');
            $proPhone = trim((string) $request->input('pro_phone'));

            $pro = User::where('phone', $proPhone)
                ->whereHas('role', fn ($q) => $q->where('slug', RoleEnum::PRO))
                ->first();

            if (!$pro) {
                return ApiResponseClass::sendError(
                    'Aucun PRO trouvé avec ce numéro.',
                    [],
                    Response::HTTP_NOT_FOUND
                );
            }

            if ($pro->id === $client->id) {
                return ApiResponseClass::sendError(
                    'Le compte PRO doit être différent de votre compte.',
                    [],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $clientWallet = $this->walletService->getWalletByUserId($client->id);

            try {
                $proWallet = $this->walletService->getWalletByUserId($pro->id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $created = $this->walletService->createWalletForUser($pro->id);
                $proWallet = $created['wallet'];
            }

            $cashoutFeePercent = $this->getOrCreatePercentSetting(
                key: 'client_cashout_fee_percent',
                description: 'Pourcentage de frais prélevé sur le client lors d\'un retrait cash',
                order: 32,
                default: 0.0
            );
            $cashoutFeeAmount = (int) round(($amount * $cashoutFeePercent) / 100);
            $totalDebitClient = $amount + $cashoutFeeAmount;

            $available = (int) ($clientWallet->cash_available - $clientWallet->blocked_amount);
            if ($available < $totalDebitClient) {
                return ApiResponseClass::sendError(
                    "Solde insuffisant. Disponible: {$available} GNF, requis: {$totalDebitClient} GNF",
                    [
                        'available' => $available,
                        'required' => $totalDebitClient,
                        'amount' => $amount,
                        'cashout_fee_amount' => $cashoutFeeAmount,
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            DB::beginTransaction();

            $ok = $this->walletService->transfer(
                fromWalletId: $clientWallet->id,
                fromUserId: $client->id,
                toWalletId: $proWallet->id,
                toUserId: $pro->id,
                amount: $amount,
                description: "Retrait cash client {$client->display_name} chez PRO {$pro->display_name} ({$pro->phone})",
            );

            if (!$ok) {
                DB::rollBack();
                return ApiResponseClass::serverError('Échec du retrait cash chez PRO.');
            }

            $superAdmin = $this->getSuperAdmin();

            try {
                $superAdminWallet = $this->walletService->getWalletByUserId($superAdmin->id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $created = $this->walletService->createWalletForUser($superAdmin->id);
                $superAdminWallet = $created['wallet'];
            }

            if ($cashoutFeeAmount > 0) {
                $feeTransferred = $this->walletService->deposit(
                    walletId: $superAdminWallet->id,
                    userId: $superAdmin->id,
                    amount: $cashoutFeeAmount,
                    description: "Frais retrait cash client {$client->display_name} via PRO {$pro->display_name}",
                    fromUserId: $client->id,
                );

                if (!$feeTransferred) {
                    DB::rollBack();
                    return ApiResponseClass::serverError('Échec du débit des frais de retrait client.');
                }
            }

            $cashoutPercent = $this->getOrCreatePercentSetting(
                key: 'pro_gain_percent_on_client_cashout',
                description: 'Pourcentage de gain du PRO sur chaque retrait cash client',
                order: 30,
                default: 0.0
            );
            $commissionCredited = $this->creditProCommission(
                pro: $pro,
                baseAmount: $amount,
                percent: $cashoutPercent,
                reason: 'cashout_client',
                debitFromSuperAdminCash: true,
            );

            DB::commit();

            $clientWallet->refresh();
            $superAdmin->refresh();
            $proWallet->refresh();

            $adminFeeRetained = max(0, $cashoutFeeAmount - $commissionCredited);

            return ApiResponseClass::sendResponse([
                'amount'             => $amount,
                'cashout_fee_percent'=> $cashoutFeePercent,
                'cashout_fee_amount' => $cashoutFeeAmount,
                'total_debited'      => $totalDebitClient,
                'pro'                => [
                    'id'           => $pro->id,
                    'display_name' => $pro->display_name,
                    'phone'        => $pro->phone,
                ],
                'new_balance'        => $clientWallet->cash_available,
                'currency'           => $clientWallet->currency ?? 'GNF',
                'pro_gain_percent'   => $cashoutPercent,
                'pro_commission_credited' => $commissionCredited,
                'admin_fee_retained' => $adminFeeRetained,
                'admin_wallet_balance' => (int) ($superAdmin->solde_portefeuille ?? 0),
            ], 'Retrait cash validé. Présentez-vous chez le PRO pour récupérer le cash.');
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('[ClientWalletController] cashoutAtPro error', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return ApiResponseClass::serverError('Erreur lors du retrait cash chez PRO.');
        }
    }

    // ──────────────────────────────────────────────────────────────
    // POST /pro/wallet/recharge-client
    // Body: { amount, recipient_phone }
    // ──────────────────────────────────────────────────────────────
    public function rechargeClientByPro(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount'          => 'required|numeric|min:1000',
            'recipient_phone' => 'required|string|min:6|max:20',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de recharge invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $proUser = Auth::user();

            if (($proUser->role?->slug ?? null) !== RoleEnum::PRO) {
                return ApiResponseClass::sendError(
                    'Action réservée aux comptes PRO.',
                    [],
                    Response::HTTP_FORBIDDEN
                );
            }

            $amount = (int) $request->input('amount');
            $recipientPhone = trim((string) $request->input('recipient_phone'));

            $client = User::where('phone', $recipientPhone)
                ->whereHas('role', fn ($q) => $q->where('slug', RoleEnum::CLIENT->value))
                ->first();

            if (!$client) {
                return ApiResponseClass::sendError(
                    'Aucun client trouvé avec ce numéro.',
                    [],
                    Response::HTTP_NOT_FOUND
                );
            }

            if ($client->id === $proUser->id) {
                return ApiResponseClass::sendError(
                    'Le destinataire doit être différent de votre compte.',
                    [],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $proWallet = $this->walletService->getWalletByUserId($proUser->id);

            try {
                $clientWallet = $this->walletService->getWalletByUserId($client->id);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $created = $this->walletService->createWalletForUser($client->id);
                $clientWallet = $created['wallet'];
            }

            $maxClientWalletBalance = $this->getOrCreateIntegerSetting(
                key: 'max_client_wallet_balance',
                description: 'Solde maximum autorisé pour un wallet client',
                order: 21,
                default: 1000000000
            );
            $projectedClientBalance = (int) $clientWallet->cash_available + $amount;
            if ($projectedClientBalance > $maxClientWalletBalance) {
                return ApiResponseClass::sendError(
                    "Opération refusée: le solde du client dépasserait la limite autorisée ({$maxClientWalletBalance} GNF).",
                    [
                        'client_current_balance' => (int) $clientWallet->cash_available,
                        'amount' => $amount,
                        'client_projected_balance' => $projectedClientBalance,
                        'max_client_wallet_balance' => $maxClientWalletBalance,
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $available = (int) ($proWallet->cash_available - $proWallet->blocked_amount);
            if ($available < $amount) {
                return ApiResponseClass::sendError(
                    "Solde insuffisant. Disponible: {$available} GNF, requis: {$amount} GNF",
                    ['available' => $available, 'required' => $amount],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            DB::beginTransaction();

            $ok = $this->walletService->transfer(
                fromWalletId: $proWallet->id,
                fromUserId: $proUser->id,
                toWalletId: $clientWallet->id,
                toUserId: $client->id,
                amount: $amount,
                description: "Recharge client {$client->display_name} ({$client->phone}) par PRO {$proUser->display_name}",
            );

            if (!$ok) {
                DB::rollBack();
                return ApiResponseClass::serverError('Échec de la recharge client.');
            }

            $depositPercent = $this->getOrCreatePercentSetting(
                key: 'pro_gain_percent_on_client_deposit',
                description: 'Pourcentage de gain du PRO sur chaque dépôt/recharge client',
                order: 31,
                default: 0.0
            );
            $commissionCredited = $this->creditProCommission(
                pro: $proUser,
                baseAmount: $amount,
                percent: $depositPercent,
                reason: 'deposit_client',
                debitFromSuperAdminCash: true,
            );

            $proWallet->refresh();

            DB::commit();

            return ApiResponseClass::sendResponse([
                'amount'      => $amount,
                'client'      => [
                    'id'           => $client->id,
                    'display_name' => $client->display_name,
                    'phone'        => $client->phone,
                ],
                'new_balance' => $proWallet->cash_available,
                'currency'    => $proWallet->currency ?? 'GNF',
                'pro_gain_percent' => $depositPercent,
                'pro_commission_credited' => $commissionCredited,
            ], 'Client rechargé avec succès');
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('[ClientWalletController] rechargeClientByPro error', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return ApiResponseClass::serverError('Erreur lors de la recharge client par PRO.');
        }
    }

    // ──────────────────────────────────────────────────────────────
    // POST /client/wallet/pay-edg
    // Body (prepaid):    { compteur_type='prepaid', rst_value, amt, code, name, phone, buy_last_date }
    // Body (postpayment): { compteur_type='postpayment', rst_value, code, name, device, amt, montant, phone, total_arrear, reste_a_payer? }
    // ──────────────────────────────────────────────────────────────
    public function payEdg(Request $request): JsonResponse
    {
        // ─── Validation commune ───────────────────────────────────
        $compteurType = $request->input('compteur_type');
        if (!in_array($compteurType, ['prepaid', 'postpayment'])) {
            return ApiResponseClass::sendError(
                'compteur_type invalide. Valeurs acceptées : prepaid, postpayment.',
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // ─── Validation spécifique selon le type ─────────────────
        if ($compteurType === 'prepaid') {
            $validator = Validator::make($request->all(), [
                'rst_value'    => 'required|string',
                'amt'          => 'required|numeric|min:20000',
                'code'         => 'required|string',
                'name'         => 'required|string|max:255',
                'phone'        => 'required|string|max:20',
                'buy_last_date'=> 'required|string',
            ]);
        } else {
            $validator = Validator::make($request->all(), [
                'rst_value'   => 'required|string',
                'code'        => 'required|string',
                'name'        => 'required|string|max:255',
                'device'      => 'required|string',
                'amt'         => 'required|numeric|min:0',
                'montant'     => 'required|numeric|min:1000',
                'phone'       => 'required|string|max:20',
                'total_arrear'=> 'required|numeric|min:0',
            ]);
        }

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de paiement invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // ─── Montant à déduire du wallet ─────────────────────────
        $amount = (int) ($compteurType === 'prepaid'
            ? $request->input('amt')
            : $request->input('montant'));

        try {
            $user   = Auth::user();
            $wallet = $this->walletService->getWalletByUserId($user->id);

            $available = $wallet->cash_available - $wallet->blocked_amount;
            if ($available < $amount) {
                return ApiResponseClass::sendError(
                    "Solde wallet insuffisant. Disponible: {$available} GNF, Requis: {$amount} GNF",
                    ['available' => $available, 'required' => $amount],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            // ─── Transaction atomique ─────────────────────────────
            $superAdmin = $this->getSuperAdmin();

            $dmlResult = DB::transaction(function () use (
                $request, $compteurType, $amount, $wallet, $user, $superAdmin
            ) {
                $superWallet = $this->walletService->getWalletByUserId($superAdmin->id);

                // 1) Transfert client → super admin
                //    deposit() avec fromUserId = client débite le client ET crédite le super admin.
                //    Le super admin reçoit la liquidité avant que son float serve le DML.
                $transferred = $this->walletService->deposit(
                    walletId:    $superWallet->id,
                    userId:      $superAdmin->id,
                    amount:      $amount,
                    description: "Paiement EDG depuis wallet client #{$user->id} — compteur {$request->input('rst_value')}",
                    fromUserId:  $user->id,
                );
                if (!$transferred) {
                    throw new \RuntimeException('Échec du transfert client → super admin.');
                }

                // 2) Traitement DML via le float du super admin (comportement standard CLIENT)
                $data = $request->all();
                if ($compteurType === 'prepaid') {
                    $result = $this->dmlService->processPrepaidTransaction($data);
                } else {
                    $result = $this->dmlService->processPostPaymentTransaction($data);
                }

                if (!$result['success']) {
                    // L'exception déclenche le rollback, restaurant le wallet client
                    throw new \RuntimeException(($result['error'] ?? 'Erreur DML inconnue.') . ' Remboursement automatique effectué.');
                }

                return $result;
            });

            // Récupérer le nouveau solde du client
            $wallet->refresh();

            Log::info('[ClientWalletController] payEdg: paiement wallet réussi', [
                'client_id'          => $user->id,
                'super_admin_id'     => $superAdmin->id,
                'amount'             => $amount,
                'new_client_balance' => $wallet->cash_available,
            ]);

            return ApiResponseClass::sendResponse(
                array_merge($dmlResult['data'] ?? [], [
                    'new_wallet_balance' => $wallet->cash_available,
                    'currency'           => $wallet->currency ?? 'GNF',
                ]),
                $dmlResult['message'] ?? 'Paiement EDG effectué avec succès depuis le wallet'
            );
        } catch (\RuntimeException $e) {
            Log::warning('[ClientWalletController] payEdg business error', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return ApiResponseClass::sendError($e->getMessage(), [], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            Log::error('[ClientWalletController] payEdg error', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return ApiResponseClass::serverError('Erreur lors du paiement EDG depuis le wallet.');
        }
    }
}
