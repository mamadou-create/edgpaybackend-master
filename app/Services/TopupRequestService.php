<?php

namespace App\Services;

use App\Enums\CommissionEnum;
use App\Enums\RoleEnum;
use App\Exceptions\BusinessException;
use App\Helpers\HelperStatus;
use App\Models\Creance;
use App\Services\AuditLogService;
use App\Models\User;
use App\Models\TopupRequest;
use App\Repositories\TopupRequestRepository;
use App\Repositories\WalletRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\WalletTransactionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class TopupRequestService
{
    private const TARGET_WALLET_PRINCIPAL = 'wallet_principal';
    private const TARGET_AVOIR_CREANCE = 'avoir_creance';

    protected $topupRequestRepository;
    protected $walletRepository;
    protected $commissionRepository;
    protected $transactionRepository;
    protected $walletService;
    protected $creanceService;

    public function __construct(
        TopupRequestRepository $topupRequestRepository,
        WalletRepository $walletRepository,
        CommissionRepository $commissionRepository,
        WalletTransactionRepository $transactionRepository,
        WalletService $walletService,
        CreanceService $creanceService,
    ) {
        $this->topupRequestRepository = $topupRequestRepository;
        $this->walletRepository = $walletRepository;
        $this->commissionRepository = $commissionRepository;
        $this->transactionRepository = $transactionRepository;
        $this->walletService = $walletService;
        $this->creanceService = $creanceService;
    }

    /**
     * ✅ APPROBATION STRICTE (FINTECH)
     *
     * Exige statut_paiement (paye|impaye), verrouille la commande (lockForUpdate),
     * refuse si statut != PENDING, et journalise l'action.
     *
     * Si impaye => création automatique d'une créance liée (commande_id) + écriture ledger via CreanceService.
     */
    public function approveCommandeStrict(
        string $topupRequestId,
        string $statutPaiement,
        ?string $note = null,
    ): array {
        if (!in_array($statutPaiement, ['paye', 'impaye'], true)) {
            return [
                'success' => false,
                'message' => 'statut_paiement invalide (attendu: paye|impaye)',
                'error' => 'validation',
            ];
        }

        $approver = Auth::guard()->user();
        if (!$approver instanceof User) {
            return [
                'success' => false,
                'message' => 'Utilisateur non authentifié',
                'error' => 'unauthenticated',
            ];
        }

        try {
            return DB::transaction(function () use ($topupRequestId, $statutPaiement, $note, $approver) {
                /** @var TopupRequest|null $commande */
                $commande = TopupRequest::with(['pro', 'decider'])
                    ->where('id', $topupRequestId)
                    ->lockForUpdate()
                    ->first();

                if (!$commande) {
                    AuditLogService::tentativeInvalide('APPROBATION_COMMANDE', [
                        'topup_request_id' => $topupRequestId,
                        'reason' => 'not_found',
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Commande introuvable',
                        'error' => 'not_found',
                    ];
                }

                $avant = $commande->toArray();

                if ($commande->status !== HelperStatus::PENDING) {
                    AuditLogService::log(
                        'APPROBATION_COMMANDE',
                        $commande->id,
                        TopupRequest::class,
                        'echec',
                        $avant,
                        null,
                        [
                            'motif' => 'statut_non_en_attente',
                            'statut_actuel' => $commande->status,
                            'statut_paiement' => $statutPaiement,
                        ],
                        'topup'
                    );

                    return [
                        'success' => false,
                        'message' => 'Impossible d\'approuver : la commande n\'est pas en attente',
                        'error' => 'invalid_status',
                    ];
                }

                // ✅ Anti double traitement (couvre cas de retry si statut déjà changé)
                if (in_array($commande->status, [HelperStatus::APPROVED, HelperStatus::REJECTED, HelperStatus::CANCELLED], true)) {
                    return [
                        'success' => false,
                        'message' => 'Commande déjà traitée',
                        'error' => 'already_processed',
                    ];
                }

                // ✅ Appliquer la décision (dans la même transaction)
                $commande->statut_paiement = $statutPaiement;
                $commande->status = HelperStatus::APPROVED;
                $commande->decided_by = $approver->id;
                $commande->date_decision = now();
                if (is_string($note) && trim($note) !== '') {
                    $commande->note = trim($note);
                }
                $commande->save();

                // ✅ Traitement topup (wallet transfer / commissions) sous transaction
                $this->processTopupBasedOnRole($approver, $commande);

                $creance = null;
                if ($statutPaiement === 'impaye') {
                    $client = $commande->pro;
                    if (!$client instanceof User) {
                        throw new \RuntimeException('Client PRO introuvable pour la commande');
                    }

                    // Idempotency/anti-duplication (sécurité supplémentaire)
                    $existing = Creance::where('commande_id', $commande->id)->first();
                    if ($existing) {
                        $creance = $existing;
                    } else {
                        $description = "Recharge impayée — demande #{$commande->idempotency_key}";
                        $metadata = [
                            'commande_id' => $commande->id,
                            'topup_request_id' => $commande->id,
                            'kind' => $commande->kind,
                            'statut_paiement' => $statutPaiement,
                            'bypass_credit_limit' => false,
                        ];

                        // Recharge impayée: appliquer strictement la limite de crédit allouée.
                        $bypassCreditLimit = false;

                        $created = $this->creanceService->creerCreance(
                            $client,
                            (float) $commande->amount,
                            $description,
                            now()->addDays(30)->toDateString(),
                            $approver,
                            $metadata,
                            $bypassCreditLimit,
                        );

                        $created->commande_id = $commande->id;
                        $created->save();
                        $creance = $created;
                    }
                }

                $apres = $commande->fresh()?->toArray();

                AuditLogService::log(
                    'APPROBATION_COMMANDE',
                    $commande->id,
                    TopupRequest::class,
                    'succes',
                    $avant,
                    $apres,
                    [
                        'statut_paiement' => $statutPaiement,
                        'creance_id' => $creance?->id,
                    ],
                    'topup'
                );

                return [
                    'success' => true,
                    'message' => $statutPaiement === 'impaye'
                        ? 'Commande approuvée : créance créée (impayé)'
                        : 'Commande approuvée (payé)',
                    'data' => [
                        'commande' => $commande->fresh(),
                        'creance' => $creance,
                    ],
                ];
            }, 3);
        } catch (\Throwable $e) {
            $isBusiness = $e instanceof BusinessException;
            $publicMessage = $isBusiness
                ? $e->getMessage()
                : 'Erreur lors de l\'approbation de la commande';

            Log::error('[TopupRequestService] approveCommandeStrict failed', [
                'topup_request_id' => $topupRequestId,
                'statut_paiement' => $statutPaiement,
                'error' => $e->getMessage(),
            ]);

            AuditLogService::log(
                'APPROBATION_COMMANDE',
                $topupRequestId,
                TopupRequest::class,
                'echec',
                null,
                null,
                [
                    'statut_paiement' => $statutPaiement,
                    'exception' => $e->getMessage(),
                ],
                'topup'
            );

            return [
                'success' => false,
                'message' => $publicMessage,
                'error' => $isBusiness ? 'business_rule' : 'server_error',
            ];
        }
    }

    /**
     * ✅ Traiter l'approbation d'une demande de recharge selon le rôle de l'approbateur
     */
    public function processApproval(string $topupRequestId, string $status, ?string $reason = null): array
    {
        DB::beginTransaction();

        try {
            // Récupérer la demande
            $topupRequest = $this->topupRequestRepository->getByID($topupRequestId);

            if (!$topupRequest) {
                throw new \Exception('Demande de recharge non trouvée');
            }

            // Vérifier si déjà approuvée
            if ($topupRequest->status === HelperStatus::APPROVED) {
                throw new \Exception('Cette demande est déjà approuvée');
            }

            // Mettre à jour le statut
            $success = $this->topupRequestRepository->updateStatus($topupRequestId, $status, $reason);

            if (!$success) {
                throw new \Exception('Impossible de mettre à jour le statut de la demande');
            }

            $approver = Auth::guard()->user();

            // Traiter selon le rôle de l'approbateur
            if ($status === HelperStatus::APPROVED) {
                $this->processTopupBasedOnRole($approver, $topupRequest);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Demande traitée avec succès',
                'data' => $topupRequest
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors du traitement de la demande $topupRequestId: " . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * ✅ Traiter la recharge selon le rôle de l'approbateur
     */
    private function processTopupBasedOnRole(User $approver, $topupRequest): void
    {
        $proUserId = $topupRequest->pro_id;
        $amount = $topupRequest->amount;
        $provider = CommissionEnum::SOUS_ADMIN;
        $description = "Approvisionnement via demande #{$topupRequest->idempotency_key}";
        $target = $this->resolveBalanceTarget($topupRequest);

        $beneficiary = User::findOrFail($proUserId);

        if ($target === self::TARGET_AVOIR_CREANCE) {
            if (!in_array($topupRequest->statut_paiement, ['paye', null], true)) {
                throw new \Exception('Une recharge d\'avoir créance ne peut pas être approuvée en impayé.');
            }

            $this->processAvoirApproval(
                $approver,
                $beneficiary,
                (int) $amount,
                $provider,
                $description,
                $topupRequest,
            );

            return;
        }

        if ($approver->isSubAdmin()) {
            // ✅ Cas 1: Sous-admin approuve - Utiliser rechargeProBySubAdmin
            $this->processSubAdminApproval($approver->id, $proUserId, $amount, $provider, $description);
        } elseif ($approver->isSuperAdmin() || $approver->isAdmin()) {
            // ✅ Cas 2: Super Admin/Admin approuve - Transfert direct
            $this->processAdminApproval($approver->id, $proUserId, $amount, $provider, $description);
        } else {
            throw new \Exception('Rôle non autorisé pour approuver les recharges');
        }
    }

    private function resolveBalanceTarget(TopupRequest $topupRequest): string
    {
        $target = (string) ($topupRequest->balance_target ?? self::TARGET_WALLET_PRINCIPAL);

        return in_array($target, [self::TARGET_WALLET_PRINCIPAL, self::TARGET_AVOIR_CREANCE], true)
            ? $target
            : self::TARGET_WALLET_PRINCIPAL;
    }

    private function processAvoirApproval(
        User $approver,
        User $beneficiary,
        int $amount,
        string $provider,
        string $description,
        TopupRequest $topupRequest,
    ): void {
        if ($amount <= 0) {
            throw new \Exception('Montant invalide pour la recharge d\'avoir.');
        }

        if ($approver->isSubAdmin()) {
            if ($beneficiary->assigned_user && $beneficiary->assigned_user !== $approver->id) {
                throw new \Exception('Vous ne pouvez créditer l\'avoir que des clients qui vous sont assignés');
            }
        } elseif (!$approver->isSuperAdmin() && !$approver->isAdmin()) {
            throw new \Exception('Rôle non autorisé pour approuver les recharges d\'avoir');
        }

        $credited = $this->creanceService->creditAvoirCreance(
            $beneficiary,
            (float) $amount,
            'topup_request_avoir:' . (string) $topupRequest->id,
            [
                'topup_request_id' => (string) $topupRequest->id,
                'idempotency_key' => (string) $topupRequest->idempotency_key,
                'provider' => $provider,
                'balance_target' => self::TARGET_AVOIR_CREANCE,
                'approved_by' => (string) $approver->id,
            ],
            null,
            null,
            $approver,
            'topup_request_admin_approval'
        );

        if ($credited <= 0) {
            throw new \Exception('Impossible de créditer l\'avoir créance du client.');
        }
    }

    /**
     * ✅ Traitement par un sous-admin (avec commission)
     */
    private function processSubAdminApproval(
        string $subAdminUserId,
        string $proUserId,
        int $amount,
        string $provider,
        string $description
    ): void {
        // Vérifier les rôles
        $subAdmin = User::findOrFail($subAdminUserId);
        $proUser = User::findOrFail($proUserId);

        if (!$subAdmin->isSubAdmin()) {
            throw new \Exception("Seuls les sous-admins peuvent effectuer cette opération");
        }

        if (!$proUser->isPro()) {
            throw new \Exception("Le destinataire doit être un utilisateur PRO");
        }

        // Vérification d'assignation
        if ($proUser->assigned_user && $proUser->assigned_user !== $subAdminUserId) {
            throw new \Exception("Vous ne pouvez recharger que les PROs qui vous sont assignés");
        }

        $userFloat = $this->getOrCreateFloat($subAdmin->wallet, $provider, 0.01);

        // Calcul de la commission
        $commissionRate = $userFloat->rate;
        $commissionAmount = (int) ($amount * $commissionRate);

        // Lock des wallets
        $subAdminWallet = $this->walletRepository->findForUpdate($subAdmin->wallet->id);
        $proWallet = $this->walletRepository->findForUpdate($proUser->wallet->id);

        if (!$subAdminWallet) {
            throw new \Exception("Wallet sous-admin introuvable");
        }

        if (!$proWallet) {
            throw new \Exception("Wallet PRO introuvable");
        }

        // ✅ Vérifier le solde du sous-admin
        $subAdminAvailable = $subAdminWallet->cash_available - $subAdminWallet->blocked_amount;
        if ($subAdminAvailable < $amount) {
            throw new \Exception("Solde insuffisant pour effectuer la recharge. Disponible: $subAdminAvailable, Requis: $amount");
        }

        // ✅ 1. Débiter le sous-admin (montant complet)
        $this->walletRepository->withdraw($subAdminWallet->id, $amount);

        // ✅ 2. Créditer le PRO (montant complet)
        $this->walletRepository->credit($proWallet->id, $amount);

        // ✅ 3. Gérer la commission (si > 0)
        if ($commissionAmount > 0) {
            $this->processCommission($subAdmin, $proUser, $subAdminWallet, $amount, $commissionAmount, $commissionRate, $provider);
        }

        // ✅ 4. Créer les transactions
        $this->createSubAdminTransactions($subAdmin, $proUser, $subAdminWallet, $proWallet, $amount, $commissionAmount, $commissionRate, $provider, $description);
    }

    /**
     * ✅ Traitement de la commission
     */
    private function processCommission(
        User $subAdmin,
        User $proUser,
        $subAdminWallet,
        int $amount,
        int $commissionAmount,
        float $commissionRate,
        string $provider
    ): void {
        // Trouver le super admin
        $superAdmin = User::whereHas('role', function ($query) {
            $query->where('slug', RoleEnum::SUPER_ADMIN);
        })->first();

        if (!$superAdmin) {
            throw new \Exception("Super admin introuvable pour payer la commission");
        }

        $superAdminWallet = $superAdmin->wallet;

        // Vérifier le solde du super admin
        $superAdminAvailable = $superAdminWallet->cash_available - $superAdminWallet->blocked_amount;
        if ($superAdminAvailable < $commissionAmount) {
            throw new \Exception("Solde insuffisant du super admin pour payer la commission. Disponible: $superAdminAvailable, Requis: $commissionAmount");
        }

        // Débiter le super admin
        $this->walletRepository->withdraw($superAdminWallet->id, $commissionAmount);

        // Créditer la commission au sous-admin
        $subAdminWallet->commission_balance += $commissionAmount;
        $subAdminWallet->commission_available += $commissionAmount;
        $subAdminWallet->save();

        $subAdmin->commission_portefeuille += $commissionAmount;
        $subAdmin->save();

        // Transactions pour la commission
        $this->createCommissionTransactions($subAdmin, $superAdmin, $subAdminWallet, $superAdminWallet, $amount, $commissionAmount, $commissionRate, $provider);
    }

    /**
     * ✅ Créer les transactions pour le sous-admin
     */
    private function createSubAdminTransactions(
        User $subAdmin,
        User $proUser,
        $subAdminWallet,
        $proWallet,
        int $amount,
        int $commissionAmount,
        float $commissionRate,
        string $provider,
        string $description
    ): void {
        // Transaction pour le débit du sous-admin
        $this->transactionRepository->create([
            'wallet_id'   => $subAdminWallet->id,
            'user_id'     => $subAdmin->id,
            'amount'      => -$amount,
            'type'        => 'pro_recharge_debit',
            'reference'   => uniqid('txn_subadmin_debit_'),
            'description' => $description,
            'metadata'    => [
                'pro_user_id'     => $proUser->id,
                'provider'        => $provider,
                'amount'         => $amount,
                'commission'     => $commissionAmount,
                'commission_rate' => $commissionRate,
                'net_cost'       => $amount - $commissionAmount,
                'timestamp'      => now()->toISOString(),
            ],
        ]);

        // Transaction pour le crédit du PRO
        $this->transactionRepository->create([
            'wallet_id'   => $proWallet->id,
            'user_id'     => $proUser->id,
            'amount'      => $amount,
            'type'        => 'pro_topup',
            'reference'   => uniqid('txn_pro_topup_'),
            'description' => $description . " par " . $subAdmin->display_name,
            'metadata'    => [
                'recharged_by'    => $subAdmin->id,
                'provider'        => $provider,
                'amount'          => $amount,
                'commission_paid' => $commissionAmount,
                'commission_rate' => $commissionRate,
                'timestamp'       => now()->toISOString(),
            ],
        ]);
    }

    /**
     * ✅ Créer les transactions de commission
     */
    private function createCommissionTransactions(
        User $subAdmin,
        User $superAdmin,
        $subAdminWallet,
        $superAdminWallet,
        int $amount,
        int $commissionAmount,
        float $commissionRate,
        string $provider
    ): void {
        // Transaction pour la commission reçue par le sous-admin
        $this->transactionRepository->create([
            'wallet_id'   => $subAdminWallet->id,
            'user_id'     => $subAdmin->id,
            'amount'      => $commissionAmount,
            'type'        => 'commission_received',
            'reference'   => uniqid('txn_commission_received_'),
            'description' => "Commission recharge PRO - {$amount} GNF",
            'metadata'    => [
                'pro_user_id'     => null, // Pas de pro spécifique dans ce contexte
                'provider'        => $provider,
                'original_amount' => $amount,
                'commission_rate' => $commissionRate,
                'paid_by'         => $superAdmin->id,
                'timestamp'       => now()->toISOString(),
            ],
        ]);

        // Transaction pour la commission payée par le super admin
        $this->transactionRepository->create([
            'wallet_id'   => $superAdminWallet->id,
            'user_id'     => $superAdmin->id,
            'amount'      => -$commissionAmount,
            'type'        => 'commission_paid',
            'reference'   => uniqid('txn_commission_paid_'),
            'description' => "Commission recharge PRO payée à {$subAdmin->display_name}",
            'metadata'    => [
                'to_user_id'      => $subAdmin->id,
                'provider'        => $provider,
                'original_amount' => $amount,
                'commission_rate' => $commissionRate,
                'timestamp'       => now()->toISOString(),
            ],
        ]);
    }

    /**
     * ✅ Traitement par un admin/super-admin (transfert direct)
     */
    private function processAdminApproval(
        string $adminUserId,
        string $proUserId,
        int $amount,
        string $provider,
        string $description
    ): void {
        // Récupérer le wallet de l'admin
        $adminWallet = $this->walletService->getWalletByUserId($adminUserId);

        if (!$adminWallet) {
            throw new \Exception("Wallet admin introuvable");
        }

        // Vérifier que l'admin a suffisamment de solde
        if ($adminWallet->cash_available < $amount) {
            throw new \Exception(
                "Solde administrateur insuffisant. Disponible: {$adminWallet->cash_available}, Requis: $amount"
            );
        }

        // Récupérer le wallet du pro
        $proWallet = $this->walletService->getWalletByUserId($proUserId);

        if (!$proWallet) {
            throw new \Exception("Wallet pro introuvable");
        }

        // Effectuer le transfert direct
        $transferSuccess = $this->walletService->transfer(
            $adminWallet->id,
            $adminUserId,
            $proWallet->id,
            $proUserId,
            $amount,
            $provider,
            $description
        );

        if (!$transferSuccess) {
            throw new \Exception("Erreur lors du transfert des fonds");
        }
    }

    /**
     * ✅ Méthode helper pour obtenir ou créer un float
     */
    private function getOrCreateFloat($wallet, string $provider, float $defaultRate = 0.0)
    {
        $float = $wallet->floats()->where('provider', $provider)->first();

        if (!$float) {
            // Créer le float avec les valeurs par défaut
            $float = $wallet->floats()->create([
                'balance' => 0,
                'commission' => 0,
                'provider' => $provider,
                'rate' => $defaultRate
            ]);

            Log::info('Float créé automatiquement', [
                'wallet_id' => $wallet->id,
                'provider' => $provider,
                'rate' => $defaultRate
            ]);
        }

        return $float;
    }
}
