<?php

namespace App\Services;

use App\Enums\RoleEnum;
use App\Helpers\HelperStatus;
use App\Interfaces\CommissionRepositoryInterface;
use App\Interfaces\WalletRepositoryInterface;
use App\Interfaces\WalletTransactionRepositoryInterface;
use App\Interfaces\WithdrawalRequestRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class WalletService
{
    private WalletRepositoryInterface $walletRepository;
    private WalletTransactionRepositoryInterface $transactionRepository;
    private WithdrawalRequestRepositoryInterface $withdrawalRequestRepository;
    private CommissionRepositoryInterface $commissionRepository;

    public function __construct(
        WalletRepositoryInterface $walletRepository,
        WalletTransactionRepositoryInterface $transactionRepository,
        WithdrawalRequestRepositoryInterface $withdrawalRequestRepository,
        CommissionRepositoryInterface $commissionRepository
    ) {
        $this->walletRepository = $walletRepository;
        $this->transactionRepository = $transactionRepository;
        $this->withdrawalRequestRepository = $withdrawalRequestRepository;
        $this->commissionRepository = $commissionRepository;
    }

    /**
     * ✅ Dépôt (crédit du portefeuille et du float correspondant)
     */
    public function deposit(
        string $walletId,
        string $userId, // destinataire
        int $amount,
        ?string $description = null,
        ?string $fromUserId = null // celui qui crédite
    ): bool {
        return DB::transaction(function () use ($walletId, $userId, $amount, $description, $fromUserId) {
            // 🔒 Lock du portefeuille destinataire
            $wallet = $this->walletRepository->findForUpdate($walletId);

            if (!$wallet) {
                // Création automatique si inexistant
                $result = $this->createWalletForUser($userId);
                $wallet = $result['wallet'] ?? null;
                if (!$wallet) {
                    throw new Exception("Impossible de créer le wallet pour l'utilisateur $userId");
                }
            }

            // Récupérer le float pour calculer la commission
            // $walletFloat = $wallet->floats()->where('provider', $provider)->first();
            // if (!$walletFloat) {
            //     throw new Exception("Float pour le provider $provider introuvable");
            // }

            // 🧮 CALCUL DE LA COMMISSION
            // $commission = intval($amount * $walletFloat->rate);
            $totalDebitFromEmitter = $amount; // Montant total débité de l'émetteur

            // Si un compte source est fourni, on le débite (montant + commission)
            if ($fromUserId) {
                $fromWallet = $this->walletRepository->getByUserId($fromUserId);
                if (!$fromWallet) {
                    throw new ModelNotFoundException("Wallet du compte source introuvable");
                }

                $availableBalance = $fromWallet->cash_available - $fromWallet->blocked_amount;
                if ($availableBalance < $totalDebitFromEmitter) {
                    throw new \Exception("Solde insuffisant pour le dépôt. Disponible: $availableBalance, Demandé: $totalDebitFromEmitter");
                }

                // DÉBITER L'ÉMETTEUR (montant + commission)
                $this->walletRepository->withdraw($fromWallet->id, $totalDebitFromEmitter);

                // Débiter le float de l'émetteur (montant + commission)
                // $fromWalletFloat = $fromWallet->floats()->where('provider', $provider)->first();
                // if ($fromWalletFloat) {
                //     $fromWalletFloat->balance -= $totalDebitFromEmitter;
                //     $fromWalletFloat->save();
                // }

                // Mettre à jour le solde de l'émetteur
                $fromUser = $fromWallet->user;
                $fromUser->solde_portefeuille -= $totalDebitFromEmitter;
                if ($fromUser->solde_portefeuille < 0) $fromUser->solde_portefeuille = 0;
                $fromUser->save();

                // Transaction côté émetteur (débit du montant + commission)
                $this->transactionRepository->create([
                    'wallet_id'   => $fromWallet->id,
                    'user_id'     => $fromUserId,
                    'amount'      => -$totalDebitFromEmitter,
                    'type'        => 'transfer_out',
                    'reference'   => uniqid('txn_transfer_out_'),
                    'description' => $description ?? "Transfert de {$amount} GNF vers {$wallet->user->display_name}",
                    'metadata'    => [
                        // 'provider'   => $provider,
                        'to_user_id' => $userId,
                        'amount' => $amount,
                        // 'commission' => $commission,
                        'total_debit' => $totalDebitFromEmitter,
                        'timestamp'  => now()->toISOString(),
                    ],
                ]);
            }

            // ✅ CRÉDITER LE DESTINATAIRE (montant complet + commission)

            // // Créditer le float destinataire (montant complet)
            // $walletFloat->balance += $amount;

            // // Gestion de la commission (crédit de la commission au destinataire)
            // $walletFloat->commission += $commission;
            // $walletFloat->save();

            // Créditer le wallet destinataire (montant complet + commission)
            $wallet->cash_available += $amount;
            // $wallet->commission_available += $commission;
            $wallet->save();

            // Mise à jour du solde utilisateur destinataire (montant complet + commission)
            $user = $wallet->user;
            $user->solde_portefeuille += $amount;
            // $user->commission_portefeuille += $commission;
            $user->save();

            // Transaction côté destinataire (crédit du montant)
            $this->transactionRepository->create([
                'wallet_id'   => $walletId,
                'user_id'     => $userId,
                'amount'      => $amount,
                'type'        => 'topup',
                'reference'   => uniqid('txn_'),
                'description' => $description ?? "Dépôt de {$amount} GNF",
                'metadata'    => [
                    // 'provider'   => $provider,
                    'amount' => $amount,
                    // 'commission_received' => $commission,
                    'from_user_id' => $fromUserId,
                    'timestamp'  => now()->toISOString(),
                ],
            ]);

            // Transaction pour la commission reçue
            // $this->transactionRepository->create([
            //     'wallet_id'   => $walletId,
            //     'user_id'     => $userId,
            //     // 'amount'      => $commission,
            //     'type'        => 'commission_revenue',
            //     'reference'   => uniqid('txn_commission_'),
            //     'description' => "Commission dépôt - {$amount} GNF",
            //     'metadata'    => [
            //         // 'provider'   => $provider,
            //         'original_amount' => $amount,
            //         // 'rate' => $walletFloat->rate,
            //         'from_user_id' => $fromUserId,
            //         'timestamp'  => now()->toISOString(),
            //     ],
            // ]);

            return true;
        });
    }

    /**
     * ✅ Retrait (débit du portefeuille et du float concerné)
     */
    public function withdraw(
        string $walletId,
        string $userId,
        int $amount,
        ?string $description = null,
        ?string $toUserId = null
    ): bool {
        return DB::transaction(function () use ($walletId, $userId, $amount, $description, $toUserId) {
            // 🔒 Lock du portefeuille
            $wallet = $this->walletRepository->findForUpdate($walletId);
            if (!$wallet) {
                throw new ModelNotFoundException("Wallet introuvable");
            }

            // Récupérer le float pour calculer la commission
            // $walletFloat = $wallet->floats()->where('provider', $provider)->first();
            // if (!$walletFloat) {
            //     throw new Exception("Float pour le provider $provider introuvable");
            // }

            // 🧮 CALCUL DE LA COMMISSION ET DU MONTANT NET
            // $commission = intval($amount * $walletFloat->rate);
            $netAmount = $amount; // Montant net après commission

            $availableBalance = $wallet->cash_available - $wallet->blocked_amount;
            if ($availableBalance < $amount) {
                throw new \Exception("Solde insuffisant pour le retrait. Disponible: $availableBalance, Demandé: $amount");
            }

            // Si un compte source est fourni, on le crédite (montant NET seulement)
            if ($toUserId) {
                $fromWallet = $this->walletRepository->getByUserId($toUserId);
                if (!$fromWallet) {
                    throw new ModelNotFoundException("Wallet du compte source introuvable");
                }

                // Créditer le wallet source (montant NET seulement)
                $this->walletRepository->credit($fromWallet->id, $netAmount);

                // Créditer le float source (montant NET seulement)
                // $fromWalletFloat = $fromWallet->floats()->where('provider', $provider)->first();
                // if (!$fromWalletFloat) {
                //     throw new Exception("Float pour le provider $provider introuvable dans le wallet destination");
                // }
                // $fromWalletFloat->balance += $netAmount;
                // $fromWalletFloat->save();

                // Transaction côté récepteur
                $this->transactionRepository->create([
                    'wallet_id'   => $fromWallet->id,
                    'user_id'     => $toUserId,
                    'amount'      => $netAmount,
                    'type'        => 'credit',
                    'reference'   => uniqid('txn_credit_'),
                    'description' => $description ?? "Dépôt Retour de {$wallet->user->display_name} de {$amount} (Net: {$netAmount} GNF)",
                    'metadata'    => [
                        // 'provider'     => $provider,
                        'from_user_id' => $userId,
                        'gross_amount' => $amount,
                        'net_amount' => $netAmount,
                        // 'commission' => $commission,
                        'timestamp'    => now()->toISOString(),
                    ],
                ]);
            }

            // ✅ Débit du wallet (montant complet)
            $this->walletRepository->withdraw($walletId, $netAmount);

            // ✅ Débit du float (montant complet)
            // if ($walletFloat->balance < $netAmount) {
            //     throw new Exception("Solde insuffisant dans le float {$provider}");
            // }

            // $walletFloat->balance -= $netAmount;
            // $walletFloat->save();

            // ✅ Enregistrement de la transaction (débit du montant complet)
            $this->transactionRepository->create([
                'wallet_id'   => $walletId,
                'user_id'     => $userId,
                'amount'      => -$amount,
                'type'        => 'withdrawal',
                'reference'   => uniqid('txn_withdrawal'),
                'description' => $description ?? "Retrait de {$amount} GNF (Net: {$netAmount} GNF)",
                'metadata'    => [
                    // 'provider'   => $provider,
                    'gross_amount' => $amount,
                    'net_amount' => $netAmount,
                    // 'commission' => $commission,
                    'to_user_id' => $toUserId,
                    'timestamp'  => now()->toISOString(),
                ],
            ]);

            return true;
        });
    }





    /**
     * ✅ Recharge d'un PRO par un sous-admin avec commission payée par le super admin
     * Le sous-admin paie le montant complet au PRO, puis reçoit la commission du super admin
     */
    public function rechargeProBySubAdmin(
        string $subAdminUserId,
        string $proUserId,
        int $amount,
        string $provider,
        ?string $description = null
    ): bool {
        return DB::transaction(function () use ($subAdminUserId, $proUserId, $amount, $provider, $description) {
            try {
                // 🔒 Vérifications des rôles
                $subAdmin = User::findOrFail($subAdminUserId);
                if (!$subAdmin->isSubAdmin()) {
                    throw new \Exception("Seuls les sous-admins peuvent effectuer cette opération");
                }

                $proUser = User::findOrFail($proUserId);
                if (!$proUser->isPro()) {
                    throw new \Exception("Le destinataire doit être un utilisateur PRO");
                }

                // Vérification d'assignation
                if ($proUser->assigned_user && $proUser->assigned_user !== $subAdminUserId) {
                    throw new \Exception("Vous ne pouvez recharger que les PROs qui vous sont assignés");
                }



                // 🔒 Lock des wallets
                $subAdminWallet = $this->walletRepository->findForUpdate($subAdmin->wallet->id);
                $proWallet = $this->walletRepository->findForUpdate($proUser->wallet->id);

                if (!$subAdminWallet) {
                    throw new \Exception("Wallet sous-admin introuvable");
                }

                if (!$proWallet) {
                    throw new \Exception("Wallet PRO introuvable");
                }

                // 🔍 Récupérer les floats
                $subAdminFloat = $subAdminWallet->floats()->where('provider', $provider)->first();

                if (!$subAdminFloat) {
                    throw new \Exception("Float {$provider} introuvable pour le sous-admin");
                }


                // 🧮 Calcul de la commission
                // $commissionSetting = $this->commissionRepository->getByKey($provider);
                // if (!$commissionSetting) {
                //     throw new \Exception("Commission non trouvée pour le provider: $provider");
                // }

                // $commissionRate = $commissionSetting->value;
                // $commissionAmount = (int) ($amount * $commissionRate);

                $commissionRate = $subAdminFloat->rate;
                $commissionAmount = (int) ($amount * $commissionRate);

                // ✅ Vérifier le solde du sous-admin
                $subAdminAvailable = $subAdminWallet->cash_available - $subAdminWallet->blocked_amount;
                if ($subAdminAvailable < $amount) {
                    throw new \Exception("Solde insuffisant pour effectuer la recharge. Disponible: $subAdminAvailable, Requis: $amount");
                }

                // ✅ 1. Débiter le sous-admin (montant complet)
                $this->walletRepository->withdraw($subAdminWallet->id, $amount);

                // ✅ 2. Créditer le PRO (montant complet)
                $this->walletRepository->credit($proWallet->id, $amount);

                // ✅ 3. Si commission > 0, créditer la commission au sous-admin depuis le super admin
                if ($commissionAmount > 0) {
                    // Trouver le super admin
                    $superAdmin = User::whereHas('role', function ($query) {
                        $query->where('is_super_admin', true);
                    })->first();

                    if (!$superAdmin) {
                        throw new \Exception("Super admin introuvable pour payer la commission");
                    }

                    // Récupérer ou créer le wallet du super admin
                    $superAdminWallet = $superAdmin->wallet;

                    // Vérifier le solde du super admin
                    $superAdminAvailable = $superAdminWallet->cash_available - $superAdminWallet->blocked_amount;
                    if ($superAdminAvailable < $commissionAmount) {
                        throw new \Exception("Solde insuffisant du super admin pour payer la commission. Disponible: $superAdminAvailable, Requis: $commissionAmount");
                    }

                    // Débiter le super admin (cash disponible)
                    $this->walletRepository->withdraw($superAdminWallet->id, $commissionAmount);
                    // Créditer la commission au sous-admin (dans commission_balance)

                    $subAdminWallet->commission_balance += $commissionAmount;
                    $subAdminWallet->commission_available += $commissionAmount;
                    $subAdminWallet->save();

                    $subAdmin->commission_portefeuille += $commissionAmount;
                    $subAdmin->save();

                    // 📜 Transaction pour la commission reçue par le sous-admin
                    $this->transactionRepository->create([
                        'wallet_id'   => $subAdminWallet->id,
                        'user_id'     => $subAdminUserId,
                        'amount'      => $commissionAmount,
                        'type'        => 'commission_received',
                        'reference'   => uniqid('txn_commission_received_'),
                        'description' => "Commission recharge PRO - {$amount} GNF",
                        'metadata'    => [
                            'pro_user_id'     => $proUserId,
                            'provider'        => $provider,
                            'original_amount' => $amount,
                            'commission_rate' => $commissionRate,
                            'paid_by'         => $superAdmin->id,
                            'float_id'        => $subAdminFloat->id,
                            'wallet_solde_before' => $subAdminWallet->cash_available - $commissionAmount,
                            'wallet_solde_after' => $subAdminWallet->cash_available,
                            'timestamp'       => now()->toISOString(),
                        ],
                    ]);

                    // 📜 Transaction pour la commission payée par le super admin
                    $this->transactionRepository->create([
                        'wallet_id'   => $superAdminWallet->id,
                        'user_id'     => $superAdmin->id,
                        'amount'      => -$commissionAmount,
                        'type'        => 'commission_paid',
                        'reference'   => uniqid('txn_commission_paid_'),
                        'description' => "Commission recharge PRO payée à {$subAdmin->display_name}",
                        'metadata'    => [
                            'to_user_id'      => $subAdminUserId,
                            'pro_user_id'     => $proUserId,
                            'provider'        => $provider,
                            'original_amount' => $amount,
                            'commission_rate' => $commissionRate,
                            'wallet_solde_before' => $subAdminWallet->cash_available - $commissionAmount,
                            'wallet_solde_after' => $subAdminWallet->cash_available,
                            'timestamp'       => now()->toISOString(),
                        ],
                    ]);
                }

                // 📜 Transaction pour le débit du sous-admin
                $this->transactionRepository->create([
                    'wallet_id'   => $subAdminWallet->id,
                    'user_id'     => $subAdminUserId,
                    'amount'      => -$amount,
                    'type'        => 'pro_recharge_debit',
                    'reference'   => uniqid('txn_subadmin_debit_'),
                    'description' => $description ?? "Recharge de {$amount} GNF pour le PRO {$proUser->display_name}",
                    'metadata'    => [
                        'pro_user_id'    => $proUserId,
                        'provider'       => $provider,
                        'amount'         => $amount,
                        'commission'     => $commissionAmount,
                        'commission_rate' => $commissionRate,
                        'net_cost'       => $amount - $commissionAmount, // Coût réel après commission
                        'wallet_solde_before' => $subAdminWallet->cash_available - $commissionAmount,
                        'wallet_solde_after' => $subAdminWallet->cash_available,
                        'timestamp'      => now()->toISOString(),
                    ],
                ]);

                // 📜 Transaction pour le crédit du PRO
                $this->transactionRepository->create([
                    'wallet_id'   => $proWallet->id,
                    'user_id'     => $proUserId,
                    'amount'      => $amount,
                    'type'        => 'pro_topup',
                    'reference'   => uniqid('txn_pro_topup_'),
                    'description' => $description ?? "Recharge de {$amount} GNF par {$subAdmin->display_name}",
                    'metadata'    => [
                        'recharged_by'    => $subAdminUserId,
                        'provider'        => $provider,
                        'amount'          => $amount,
                        'commission_paid' => $commissionAmount,
                        'commission_rate' => $commissionRate,
                        'timestamp'       => now()->toISOString(),
                    ],
                ]);

                return true;
            } catch (\Exception $e) {
                Log::error("Erreur dans rechargeProBySubAdminAlternative: " . $e->getMessage(), [
                    'sub_admin_id' => $subAdminUserId,
                    'pro_user_id'  => $proUserId,
                    'provider'     => $provider,
                    'amount'       => $amount
                ]);
                throw $e;
            }
        });
    }

    /**
     * ✅ Transfert entre deux wallets (débit source + crédit destination)
     */
    public function transfer(
        string $fromWalletId,
        string $fromUserId,
        string $toWalletId,
        string $toUserId,
        int $amount,
        ?string $description = null
    ): bool {
        return DB::transaction(function () use ($fromWalletId, $fromUserId, $toWalletId, $toUserId, $amount, $description) {
            try {
                // 🔒 Lock des deux wallets
                $fromWallet = $this->walletRepository->findForUpdate($fromWalletId);
                $toWallet = $this->walletRepository->findForUpdate($toWalletId);

                if (!$fromWallet || !$toWallet) {
                    throw new ModelNotFoundException("Wallet source ou destination introuvable");
                }

                // Récupérer le float destination pour calculer la commission
                // $toWalletFloat = $toWallet->floats()->where('provider', $provider)->first();
                // if (!$toWalletFloat) {
                //     throw new Exception("Float pour le provider $provider introuvable dans le wallet destination");
                // }

                // 🧮 CALCUL DE LA COMMISSION
                // $commission = intval($amount * $toWalletFloat->rate);
                // $totalAmount = $amount + $commission;

                $totalAmount = $amount;

                // Vérifier le solde source (montant + commission)
                $availableBalance = $fromWallet->cash_available - $fromWallet->blocked_amount;
                if ($availableBalance < $totalAmount) {
                    throw new \Exception("Solde insuffisant pour le transfert. Disponible: $availableBalance, Demandé: $totalAmount");
                }

                // Vérifier le float source
                // $fromWalletFloat = $fromWallet->floats()->where('provider', $provider)->first();
                // if (!$fromWalletFloat) {
                //     throw new Exception("Float pour le provider $provider introuvable dans le wallet source");
                // }
                // if ($fromWalletFloat->balance < $amount) {
                //     throw new Exception("Solde insuffisant dans le float source pour le provider $provider. Solde float: {$fromWalletFloat->balance}, Montant à transférer: $amount");
                // }

                $fromUser = $fromWallet->user;

                // ✅ Débit du wallet source (montant + commission)
                $this->walletRepository->withdraw($fromWalletId, $totalAmount);

                // ✅ Débit du float source (montant seulement, la commission n'est pas prise sur le float)
                // $fromWalletFloat->balance -= $amount;
                // $fromWalletFloat->save();

                // ✅ Crédit du wallet destination (montant seulement, la commission est enregistrée séparément)
                $this->walletRepository->credit($toWalletId, $amount);

                // ✅ Crédit du float destination (montant seulement)
                // $toWalletFloat->balance += $amount;
                // $toWalletFloat->commission += $commission;
                // $toWalletFloat->save();

                // ✅ Mise à jour des commissions du wallet destination
                // $toWallet->commission_available += $commission;
                // $toWallet->save();

                // ✅ Mise à jour du solde utilisateur destination
                $toUser = $toWallet->user;
                // $toUser->commission_portefeuille += $commission;
                // $toUser->save();

                // ✅ Enregistrement transaction côté source (débit)
                $this->transactionRepository->create([
                    'wallet_id'   => $fromWalletId,
                    'user_id'     => $fromUserId,
                    'amount'      => -$totalAmount,
                    'type'        => 'transfer_out',
                    'reference'   => uniqid('txn_transfer_out_'),
                    'description' => $description ?? "Transfert de {$amount} GNF vers {$toUser->display_name}",
                    'metadata'    => [
                        // 'provider'   => $provider,
                        'to_user_id' => $toUserId,
                        'amount' => $amount,
                        //'commission' => $commission,
                        'total_debited' => $totalAmount,
                        'ip'         => request()->ip(),
                        'user_agent' => request()->userAgent(),
                        'timestamp'  => now()->toISOString(),
                    ],
                ]);

                // ✅ Enregistrement transaction côté destination (crédit)
                $this->transactionRepository->create([
                    'wallet_id'   => $toWalletId,
                    'user_id'     => $toUserId,
                    'amount'      => $amount,
                    'type'        => 'transfer_in',
                    'reference'   => uniqid('txn_transfer_in_'),
                    'description' => $description ?? "Réception transfert de {$amount} GNF de {$fromUser->display_name}",
                    'metadata'    => [
                        // 'provider'     => $provider,
                        'from_user_id' => $fromUserId,
                        //'commission'   => $commission,
                        'ip'           => request()->ip(),
                        'user_agent'   => request()->userAgent(),
                        'timestamp'    => now()->toISOString(),
                    ],
                ]);

                return true;
            } catch (\Exception $e) {
                Log::error("Erreur lors du transfert de $fromWalletId vers $toWalletId: " . $e->getMessage());
                throw $e;
            }
        });
    }


    public function creditWallet(
        string $fromUserId,
        string $toUserId,
        int $amount
    ): bool {
        return DB::transaction(function () use ($fromUserId, $toUserId, $amount) {

            // Récupération des wallets
            $fromWallet = $this->walletRepository->getByUserId($fromUserId);
            $toWallet   = $this->walletRepository->getByUserId($toUserId);

            if (!$toWallet) {
                throw new Exception("Le wallet du destinataire est introuvable.");
            }

            $fromUser = $fromWallet?->user;
            $toUser   = $toWallet->user;

            // Vérifier que le destinataire est PRO
            if ($toUser->role->slug !== RoleEnum::PRO) {
                throw new Exception("Vous ne pouvez créditer que des utilisateurs PRO.");
            }

            // Droits selon le rôle de l'émetteur
            switch ($fromUser->role->slug) {
                case RoleEnum::SUPER_ADMIN:
                    // Super admin peut tout faire
                    break;

                case RoleEnum::FINANCE_ADMIN:
                case RoleEnum::SUPPORT_ADMIN:
                case RoleEnum::COMMERCIAL_ADMIN:
                    if ($toUser->assigned_user !== $fromUser->id) {
                        throw new Exception("Vous ne pouvez créditer que les PROs qui vous sont assignés.");
                    }

                    // Récupération du float
                    // $float = $fromWallet->floats()->where('provider', $provider)->first();
                    // if (!$float) {
                    //     throw new Exception("Float {$provider} introuvable pour {$fromUser->name}");
                    // }

                    // if ($float->balance < $amount) {
                    //     throw new Exception("Solde insuffisant dans le float {$provider}");
                    // }

                    // Débit du float
                    // $float->balance -= $amount;

                    // // Calcul de la commission
                    // $commission = intval($amount * $float->rate);
                    // $float->commission += $commission;
                    // $float->save();

                    // Débit du solde global de l'émetteur
                    $this->walletRepository->withdraw($fromWallet->id, $amount);

                    // Mise à jour du solde utilisateur
                    $fromUser->solde_portefeuille -= $amount;
                    if ($fromUser->solde_portefeuille < 0) $fromUser->solde_portefeuille = 0;
                    $fromUser->save();

                    break;

                default:
                    throw new Exception("Vous n'avez pas la permission d'effectuer cette opération.");
            }

            // Crédit du wallet PRO
            $this->walletRepository->updateBalance($toWallet->id, $amount);

            // Mise à jour du solde utilisateur
            $toUser->solde_portefeuille += $amount;
            $toUser->save();

            // Transactions ledger
            $this->transactionRepository->create([
                'wallet_id'   => $toWallet->id,
                'user_id'     => $toUserId,
                'amount'      => $amount,
                'type'        => 'credit',
                'reference'   => uniqid('txn_credit_'),
                'description' => "Crédit de {$amount} GNF de {$fromUser->name} à {$toUser->name}",
                'metadata'    => [
                    // 'provider'  => $provider,
                    'from_user' => $fromUserId,
                    'timestamp' => now()->toISOString(),
                ],
            ]);

            if (isset($fromWallet)) {
                $this->transactionRepository->create([
                    'wallet_id'   => $fromWallet->id,
                    'user_id'     => $fromUserId,
                    'amount'      => -$amount,
                    'type'        => 'debit',
                    'reference'   => uniqid('txn_debit_'),
                    'description' => "Débit de {$amount} GNF vers {$toUser->name}",
                    'metadata'    => [
                        // 'provider' => $provider,
                        'to_user'  => $toUserId,
                        'timestamp' => now()->toISOString(),
                    ],
                ]);
            }

            return true;
        });
    }


    /**
     * Création d'un wallet avec floats par défaut
     */
    public function createWalletForUser(string $userId, int $initialBalance = 0): ?array
    {
        DB::beginTransaction();

        try {
            if ($this->walletRepository->walletExistsForUser($userId)) {
                $existingWallet = $this->walletRepository->getByUserId($userId);
                DB::commit();
                return [
                    'wallet' => $existingWallet,
                    'created' => false,
                    'message' => 'Wallet existant trouvé'
                ];
            }

            $walletData = [
                'user_id' => $userId,
                'currency' => 'GNF',
                'cash_available' => $initialBalance,
                'commission_available' => 0,
                'commission_balance' => 0,
                'blocked_amount' => 0,
            ];

            $wallet = $this->walletRepository->create($walletData);

            if (!$wallet) {
                throw new Exception('Erreur lors de la création du wallet');
            }

            // ✅ Floats par défaut
            // $defaultFloats = [
            //     ['provider' => HelperStatus::SOURCE_EDG, 'balance' => 0, 'commission' => 0, 'rate' => 0.01],
            //     ['provider' => HelperStatus::SOURCE_GSS, 'balance' => 0, 'commission' => 0, 'rate' => 0.01],
            //     ['provider' => HelperStatus::SOURCE_CASH, 'balance' => 0, 'commission' => 0, 'rate' => 0.01],
            // ];

            // foreach ($defaultFloats as $float) {
            //     $this->walletRepository->addFloat(
            //         $wallet->id,
            //         $float['provider'],
            //         $float['balance'],
            //         $float['commission'],
            //         $float['rate']
            //     );
            // }

            DB::commit();

            return [
                'wallet' => $wallet,
                'created' => true,
                'message' => 'Wallet créé avec succès'
            ];
        } catch (Exception $e) {
            DB::rollBack();
            logger()->error("Erreur création wallet utilisateur $userId : " . $e->getMessage());
            return null;
        }
    }

    /**
     * Transfert commission -> solde
     */
    public function transferCommission(string $walletId, string $userId, int $amount): bool
    {
        DB::beginTransaction();

        try {
            $wallet = $this->walletRepository->findForUpdate($walletId);

            if (!$wallet || $wallet->user_id !== $userId) {
                throw new Exception('Wallet non trouvé ou non autorisé');
            }

            $success = $this->walletRepository->transferCommissionToBalance($walletId, $amount);

            // ✅ Débit du float correspondant
            // $walletFloat = $wallet->floats()->where('provider', $provider)->first();
            // if (!$walletFloat) {
            //     throw new Exception("Float pour le provider $provider introuvable");
            // }

            // if ($walletFloat->commission < $amount) {
            //     throw new Exception("Solde insuffisant dans le float {$provider}");
            // }

            // $walletFloat->commission -= $amount;
            // $walletFloat->balance += $amount;
            // $walletFloat->save();

            // ✅ Mise à jour du solde utilisateur
            $user = $wallet->user;
            $user->commission_portefeuille -= $amount;
            $user->solde_portefeuille += $amount;
            if ($user->commission_portefeuille < 0) $user->commission_portefeuille = 0; // sécurité
            $user->save();

            if ($success) {
                $this->transactionRepository->create([
                    'wallet_id' => $walletId,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'type' => 'commission_transfer',
                    'description' => 'Transfert commission vers solde',
                ]);
            }

            DB::commit();
            return $success;
        } catch (Exception $e) {
            DB::rollBack();
            logger()->error("Erreur transfert commission $walletId : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Transférer de l'argent entre deux floats via un wallet spécifique
     *
     * @param string $walletId ID du wallet utilisé pour le transfert
     * @param string $floatFrom ID du float émetteur
     * @param string $floatTo ID du float bénéficiaire
     * @param int $amount Montant à transférer (en centimes ou unité de base)
     * @param string|null $description Description du transfert
     * @return bool
     */

    public function transferBetweenFloats(
        string $walletId,
        string $floatFromId,
        string $floatToId,
        int $amount,
        ?string $description = null
    ): bool {
        try {
            return DB::transaction(function () use ($walletId, $floatFromId, $floatToId, $amount, $description) {
                // Validation des paramètres
                if ($amount <= 0) {
                    throw new \Exception("Le montant du transfert doit être supérieur à 0");
                }

                if ($floatFromId === $floatToId) {
                    throw new \Exception("Impossible de transférer vers le même float");
                }

                // Récupération et verrouillage du wallet
                $wallet = $this->walletRepository->findForUpdate($walletId);
                if (!$wallet) {
                    throw new \Exception("Wallet non trouvé");
                }

                // Récupération et verrouillage des floats
                // $fromFloat = $this->walletRepository->getFloatByIdForUpdate($floatFromId);
                // $toFloat = $this->walletRepository->getFloatByIdForUpdate($floatToId);

                $fromFloat = $wallet->floats()->where('id', $floatFromId)->first();
                $toFloat = $wallet->floats()->where('id', $floatToId)->first();

                if (!$fromFloat) {
                    throw new \Exception("Float émetteur non trouvé");
                }

                if (!$toFloat) {
                    throw new \Exception("Float bénéficiaire non trouvé");
                }

                // Vérifier que les floats appartiennent au wallet
                if ($fromFloat->wallet_id !== $walletId || $toFloat->wallet_id !== $walletId) {
                    throw new \Exception("Les floats n'appartiennent pas au wallet spécifié");
                }

                // Vérification du solde suffisant sur le float émetteur
                if ($fromFloat->balance < $amount) {
                    throw new \Exception("Solde insuffisant sur le float émetteur");
                }

                // Effectuer le transfert
                // $fromFloat->balance -= $amount;
                // $toFloat->balance += $amount;

                // Sauvegarder les modifications
                // $this->walletRepository->updateFloatBalance($floatFromId, $fromFloat->balance);
                // $this->walletRepository->updateFloatBalance($floatToId, $toFloat->balance);

                $user = $wallet->user;

                // Enregistrer la transaction entre floats
                $this->transactionRepository->create([
                    'wallet_id'   => $walletId,
                    'user_id'     => $user->id,
                    'amount' => $amount,
                    'currency' => $fromFloat->currency,
                    'type' => 'wallet_float_transfer',
                    'description' => $description ?? "Transfert entre floats via wallet",
                    'reference'   => uniqid('txn_transfert_float_'),
                    'metadata' => [
                        'provider' => $toFloat->name,
                        'initiated_by' => Auth::id() ?? null,
                        'wallet_id' => $walletId,
                        'wallet_type' => $wallet->type,
                        'transfer_amount_original' => $amount,
                        'timestamp' => now()->toISOString(),
                    ]
                ]);

                Log::info("Transfert entre floats via wallet réussi", [
                    'wallet_id' => $walletId,
                    'from_float' => $floatFromId,
                    'to_float' => $floatToId,
                    'amount' => $amount,
                    'initiated_by' => Auth::id() ?? 'system'
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Erreur lors du transfert entre floats: ' . $e->getMessage(), [
                'wallet_id' => $walletId,
                'from_float' => $floatFromId,
                'to_float' => $floatToId,
                'amount' => $amount,
                'initiated_by' => Auth::id() ?? 'system'
            ]);

            return false;
        }
    }


    /**
     * Transférer de l'argent entre deux floats via un wallet spécifique
     *
     * @param string $walletId ID du wallet utilisé pour le transfert
     * @param string $floatFrom Provider du float émetteur
     * @param string $floatTo Provider du float bénéficiaire
     * @param int $amount Montant à transférer (en centimes ou unité de base)
     * @param string|null $description Description du transfert
     * @return bool
     */

    public function transferBetweenFloatProviders(
        string $walletId,
        string $floatFrom,
        string $floatTo,
        int $amount,
        ?string $description = null
    ): bool {
        try {
            return DB::transaction(function () use ($walletId, $floatFrom, $floatTo, $amount, $description) {
                // Validation des paramètres
                if ($amount <= 0) {
                    throw new \Exception("Le montant du transfert doit être supérieur à 0");
                }

                if ($floatFrom === $floatTo) {
                    throw new \Exception("Impossible de transférer vers le même float");
                }

                // Récupération et verrouillage du wallet
                $wallet = $this->walletRepository->findForUpdate($walletId);
                if (!$wallet) {
                    throw new \Exception("Wallet non trouvé");
                }

                // Récupération et verrouillage des floats
                $fromFloat = $wallet->floats()->where('provider', $floatFrom)->first();
                $toFloat = $wallet->floats()->where('provider', $floatTo)->first();

                if (!$fromFloat) {
                    throw new \Exception("Float émetteur non trouvé");
                }

                if (!$toFloat) {
                    throw new \Exception("Float bénéficiaire non trouvé");
                }

                // Vérifier que les floats appartiennent au wallet
                if ($fromFloat->wallet_id !== $walletId || $toFloat->wallet_id !== $walletId) {
                    throw new \Exception("Les floats n'appartiennent pas au wallet spécifié");
                }

                // Vérification du solde suffisant sur le float émetteur
                if ($fromFloat->balance < $amount) {
                    throw new \Exception("Solde insuffisant sur le float émetteur");
                }

                // Effectuer le transfert
                // $fromFloat->balance -= $amount;
                // $toFloat->balance += $amount;

                // Sauvegarder les modifications
                $this->walletRepository->updateFloatBalance($fromFloat->id, $fromFloat->balance);
                $this->walletRepository->updateFloatBalance($toFloat->id, $toFloat->balance);

                $user = $wallet->user;

                // Enregistrer la transaction entre floats
                $this->transactionRepository->create([
                    'wallet_id'   => $walletId,
                    'user_id'     => $user->id,
                    'amount' => $amount,
                    'currency' => $fromFloat->currency,
                    'type' => 'wallet_float_transfer',
                    'description' => $description ?? "Transfert entre floats via wallet",
                    'reference'   => uniqid('txn_transfert_float_'),
                    'metadata' => [
                        'provider' => $toFloat->name,
                        'initiated_by' => Auth::id() ?? null,
                        'wallet_id' => $walletId,
                        'wallet_type' => $wallet->type,
                        'transfer_amount_original' => $amount,
                        'timestamp' => now()->toISOString(),
                    ]
                ]);

                Log::info("Transfert entre floats via wallet réussi", [
                    'wallet_id' => $walletId,
                    'from_float' => $fromFloat,
                    'to_float' => $toFloat,
                    'amount' => $amount,
                    'initiated_by' => Auth::id() ?? 'system'
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Erreur lors du transfert entre floats: ' . $e->getMessage(), [
                'wallet_id' => $walletId,
                'from_float' => $fromFloat,
                'to_float' => $floatTo,
                'amount' => $amount,
                'initiated_by' => Auth::id() ?? 'system'
            ]);

            return false;
        }
    }




    /**
     * Récupérer le wallet d'un utilisateur
     */
    public function getWalletByUserId(string $userId)
    {
        $wallet = $this->walletRepository->getByUserId($userId);
        if (!$wallet) {
            throw new ModelNotFoundException("Aucun wallet trouvé pour l'utilisateur $userId");
        }
        return $wallet;
    }

    /**
     * 📥 DEMANDE DE RETRAIT - Débiter un compte et créditer un autre
     */
    public function withdrawalRequest(
        string $fromUserId,
        string $toUserId,
        int $amount,
        ?string $description = null,
        ?array $metadata = null
    ): array {
        DB::beginTransaction();
        try {
            // Vérifier que l'utilisateur source a suffisamment de fonds
            $fromWallet = $this->walletRepository->getByUserId($fromUserId);
            if (!$fromWallet) {
                throw new \Exception("Wallet source introuvable pour l'utilisateur: $fromUserId");
            }

            // Vérifier le solde disponible (en excluant les fonds bloqués)
            $availableBalance = $fromWallet->cash_available - $fromWallet->blocked_amount;
            if ($availableBalance < $amount) {
                throw new \Exception("Solde insuffisant pour le retrait. Disponible: $availableBalance, Demandé: $amount");
            }

            // Bloquer les fonds
            $this->walletRepository->blockAmount($fromWallet->id, $amount);

            // Créer la demande de retrait
            $withdrawalData = [
                'wallet_id' => $fromWallet->id,
                'user_id' => $fromUserId,
                'amount' => $amount,
                'currency' => 'GNF',
                'status' => HelperStatus::PENDING,
                'description' => $description ?? "Demande de retrait vers $toUserId",
                'metadata' => array_merge($metadata ?? [], [
                    'to_user_id' => $toUserId,
                    'requested_at' => now()->toISOString(),
                ])
            ];

            $withdrawalRequest = $this->withdrawalRequestRepository->create($withdrawalData);

            if (!$withdrawalRequest) {
                throw new \Exception("Échec de la création de la demande de retrait");
            }

            // Bloquer les fonds
            $fromWallet->blocked_amount += $amount;
            $fromWallet->save();

            DB::commit();

            Log::info("Demande de retrait créée avec fonds bloqués", [
                'withdrawal_request_id' => $withdrawalRequest->id,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'amount' => $amount,
                'blocked_amount' => $fromWallet->blocked_amount
            ]);

            return [
                'success' => true,
                'withdrawal_request_id' => $withdrawalRequest->id,
                'status' => HelperStatus::PENDING,
                'message' => 'Demande de retrait créée avec succès',
                'blocked_amount' => $fromWallet->blocked_amount
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de la création de la demande de retrait: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Approuver une demande de retrait - Avec blocked_amount
     */
    public function approveWithdrawal(
        string $withdrawalRequestId,
        string $processedBy,
        ?string $notes = null
    ): array {
        DB::beginTransaction();
        try {
            // Récupérer la demande de retrait
            $withdrawalRequest = $this->withdrawalRequestRepository->getById($withdrawalRequestId);
            if (!$withdrawalRequest) {
                throw new \Exception("Demande de retrait introuvable");
            }

            // Vérifier que la demande est en attente
            if ($withdrawalRequest->status !== HelperStatus::PENDING) {
                throw new \Exception("Seules les demandes en attente peuvent être approuvées. Statut actuel: {$withdrawalRequest->status}");
            }

            // Récupérer le wallet de l'utilisateur qui retire
            $userWallet = $this->walletRepository->getById($withdrawalRequest->wallet_id);
            if (!$userWallet) {
                throw new \Exception("Wallet utilisateur introuvable");
            }

            // Récupérer le wallet de l'agent qui approuve (processed_by)
            $agentWallet = $this->walletRepository->getByUserId($processedBy);
            if (!$agentWallet) {
                throw new \Exception("Wallet agent introuvable");
            }

            // Vérifier que les fonds bloqués sont toujours disponibles
            if ($userWallet->blocked_amount < $withdrawalRequest->amount) {
                throw new \Exception("Fonds bloqués insuffisants pour approuver le retrait");
            }


            // Récupérer le float de l'agent pour calculer la commission
            // $agentWalletFloat = $agentWallet->floats()->where('provider', $withdrawalRequest->provider)->first();
            // if (!$agentWalletFloat) {
            //     throw new \Exception("Float agent introuvable pour le provider: {$withdrawalRequest->provider}");
            // }


            // // Récupérer le float de user pour calculer la commission
            // $userWalletFloat = $userWallet->floats()->where('provider', $withdrawalRequest->provider)->first();
            // if (!$userWalletFloat) {
            //     throw new \Exception("Float agent introuvable pour le provider: {$withdrawalRequest->provider}");
            // }


            // 🧮 CALCUL DE LA COMMISSION ET DU MONTANT NET
            // $commission = intval($withdrawalRequest->amount * $userWalletFloat->rate);
            // $netAmount = $withdrawalRequest->amount + $commission; // Montant que l'agent reçoit réellement

            // Mettre à jour le statut de la demande
            $this->withdrawalRequestRepository->updateStatus($withdrawalRequestId, HelperStatus::APPROVED, [
                'processed_by' => $processedBy,
                'processed_at' => now(),
                'processing_notes' => $notes
            ]);

            // 1. Débloquer et retirer les fonds du wallet utilisateur (montant complet de la demande)
            $this->walletRepository->unblockAndWithdraw($userWallet->id, $withdrawalRequest->amount);

            // 2. CRÉDITER LE WALLET DE L'AGENT avec le montant NET seulement (sans la commission)
            $this->walletRepository->credit($agentWallet->id, $withdrawalRequest->amount);

            // 3. Gestion des floats - Débiter le float de l'utilisateur (montant COMPLET - pas net!)
            // $userWalletFloat = $userWallet->floats()->where('provider', $withdrawalRequest->provider)->first();
            // if ($userWalletFloat) {
            //     // ⚠️ CORRECTION : Débiter le montant COMPLET, pas le net
            //     $userWalletFloat->balance -= $netAmount; // Montant complet
            //     $userWalletFloat->save();
            // }

            // 4. Gestion des floats - Créditer le float de l'agent (montant NET seulement)
            // $agentWalletFloat->balance += $netAmount;
            // $agentWalletFloat->save();



            // 5. Créer une transaction pour le retrait (débit utilisateur)
            $this->transactionRepository->create([
                'wallet_id' => $userWallet->id,
                'user_id' => $withdrawalRequest->user_id,
                'amount' => -$withdrawalRequest->amount,
                'type' => 'withdrawal_approved',
                'reference'   => uniqid('txn_withdrawal_approved_'),
                'description' => $withdrawalRequest->description ?? "Retrait approuvé - Demande #{$withdrawalRequest->id}",
                'metadata' => [
                    'approved_by' => $processedBy,
                    'approved_at' => now()->toISOString(),
                    'notes' => $notes,
                    //'commission' => $commission,
                    'net_amount' => $withdrawalRequest->amount
                ]
            ]);

            // 6. Créer une transaction pour le crédit agent (montant NET seulement)
            $this->transactionRepository->create([
                'wallet_id' => $agentWallet->id,
                'user_id' => $processedBy,
                'amount' => $withdrawalRequest->amount,
                'type' => 'withdrawal_credit',
                'reference'   => uniqid('txn_agent_credit_'),
                'description' => "Crédit retrait - Demande #{$withdrawalRequest->id}",
                'metadata' => [
                    'withdrawal_request_id' => $withdrawalRequest->id,
                    'original_user_id' => $withdrawalRequest->user_id,
                    'gross_amount' => $withdrawalRequest->amount,
                    'net_amount' => $withdrawalRequest->amount,
                    //'commission' => $commission,
                    'approved_at' => now()->toISOString(),
                    'notes' => $notes
                ]
            ]);

            // 7. Mettre à jour le solde utilisateur (qui retire) - Montant COMPLET
            $user = $userWallet->user;
            $user->solde_portefeuille -= $withdrawalRequest->amount; // ⚠️ Montant COMPLET
            if ($user->solde_portefeuille < 0) $user->solde_portefeuille = 0;
            $user->save();

            DB::commit();

            Log::info("Demande de retrait approuvée avec commission reversée", [
                'withdrawal_request_id' => $withdrawalRequestId,
                'processed_by' => $processedBy,
                'gross_amount' => $withdrawalRequest->amount,
                //'commission' => $commission,
                'net_amount' => $withdrawalRequest->amount,
                'total_agent_credit' => $withdrawalRequest->amount,
                'user_wallet_id' => $userWallet->id,
                'agent_wallet_id' => $agentWallet->id
            ]);

            return [
                'success' => true,
                'withdrawal_request_id' => $withdrawalRequestId,
                'status' => 'approved',
                'message' => 'Demande de retrait approuvée avec succès',
                'gross_amount' => $withdrawalRequest->amount,
                //'commission' => $commission,
                'net_amount' => $withdrawalRequest->amount,
                'total_agent_credit' => $withdrawalRequest->amount
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de l'approbation de la demande de retrait: " . $e->getMessage(), [
                'withdrawal_request_id' => $withdrawalRequestId,
                'processed_by' => $processedBy
            ]);
            throw $e;
        }
    }

    /**
     * Rejeter une demande de retrait - Avec blocked_amount
     */
    public function rejectWithdrawal(
        string $withdrawalRequestId,
        string $rejectedBy,
        string $reason
    ): array {
        DB::beginTransaction();
        try {
            // Récupérer la demande de retrait
            $withdrawalRequest = $this->withdrawalRequestRepository->getById($withdrawalRequestId);
            if (!$withdrawalRequest) {
                throw new \Exception("Demande de retrait introuvable");
            }

            // Vérifier que la demande est en attente
            if ($withdrawalRequest->status !== 'pending') {
                throw new \Exception("Seules les demandes en attente peuvent être rejetées. Statut actuel: {$withdrawalRequest->status}");
            }

            // Récupérer le wallet
            $wallet = $this->walletRepository->getById($withdrawalRequest->wallet_id);
            if (!$wallet) {
                throw new \Exception("Wallet introuvable");
            }

            // Mettre à jour le statut de la demande
            $this->withdrawalRequestRepository->updateStatus($withdrawalRequestId, HelperStatus::REJECTED, [
                'processed_by' => $rejectedBy,
                'processed_at' => now(),
                'processing_notes' => $reason
            ]);

            // Débloquer les fonds
            $this->walletRepository->unblockAmount($wallet->id, $withdrawalRequest->amount);

            // Créer une transaction pour le rejet
            $this->transactionRepository->create([
                'wallet_id' => $wallet->id,
                'user_id' => $withdrawalRequest->user_id,
                'amount' => 0,
                'type' => 'withdrawal_rejected',
                'reference'   => uniqid('txn_withdrawal_rejected_'),
                'description' => "Retrait rejeté - {$reason}",
                'metadata' => [
                    'withdrawal_request_id' => $withdrawalRequest->id,
                    'rejected_by' => $rejectedBy,
                    'rejected_at' => now()->toISOString(),
                    'reason' => $reason
                ]
            ]);

            DB::commit();

            Log::info("Demande de retrait rejetée avec déblocage", [
                'withdrawal_request_id' => $withdrawalRequestId,
                'rejected_by' => $rejectedBy,
                'reason' => $reason,
                'amount_unblocked' => $withdrawalRequest->amount,
                'new_blocked_amount' => $wallet->blocked_amount
            ]);

            return [
                'success' => true,
                'withdrawal_request_id' => $withdrawalRequestId,
                'status' => 'rejected',
                'message' => 'Demande de retrait rejetée avec succès',
                'blocked_amount' => $wallet->blocked_amount
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors du rejet de la demande de retrait: " . $e->getMessage(), [
                'withdrawal_request_id' => $withdrawalRequestId,
                'rejected_by' => $rejectedBy
            ]);
            throw $e;
        }
    }

    /**
     * Annuler une demande de retrait - Avec blocked_amount
     */
    public function cancelWithdrawal(
        string $withdrawalRequestId,
        string $cancelledBy,
        ?string $reason = null
    ): array {
        DB::beginTransaction();
        try {
            // Récupérer la demande de retrait
            $withdrawalRequest = $this->withdrawalRequestRepository->getById($withdrawalRequestId);
            if (!$withdrawalRequest) {
                throw new \Exception("Demande de retrait introuvable");
            }

            // Vérifier que la demande est en attente
            if ($withdrawalRequest->status !== 'pending') {
                throw new \Exception("Seules les demandes en attente peuvent être annulées. Statut actuel: {$withdrawalRequest->status}");
            }

            // Vérifier que l'utilisateur qui annule est bien le propriétaire
            if ($withdrawalRequest->user_id !== $cancelledBy) {
                throw new \Exception("Seul le propriétaire de la demande peut l'annuler");
            }

            // Récupérer le wallet
            $wallet = $this->walletRepository->getById($withdrawalRequest->wallet_id);
            if (!$wallet) {
                throw new \Exception("Wallet introuvable");
            }

            // Mettre à jour le statut de la demande
            $this->withdrawalRequestRepository->updateStatus($withdrawalRequestId, HelperStatus::CANCELLED, [
                'processed_by' => $cancelledBy,
                'processed_at' => now(),
                'processing_notes' => $reason ?? "Annulé par l'utilisateur"
            ]);

            // Débloquer les fonds
            $this->walletRepository->unblockAmount($wallet->id, $withdrawalRequest->amount);

            // Créer une transaction pour l'annulation
            $this->transactionRepository->create([
                'wallet_id' => $wallet->id,
                'user_id' => $withdrawalRequest->user_id,
                'amount' => 0,
                'type' => 'withdrawal_cancelled',
                'reference' => uniqid('txn_withdrawal_cancelled_'),
                'description' => "Retrait annulé - " . ($reason ?? "Annulé par l'utilisateur"),
                'metadata' => [
                    'withdrawal_request_id' => $withdrawalRequest->id,
                    'cancelled_by' => $cancelledBy,
                    'cancelled_at' => now()->toISOString(),
                    'reason' => $reason
                ]
            ]);

            DB::commit();

            Log::info("Demande de retrait annulée avec déblocage", [
                'withdrawal_request_id' => $withdrawalRequestId,
                'cancelled_by' => $cancelledBy,
                'reason' => $reason,
                'amount_unblocked' => $withdrawalRequest->amount,
                'new_blocked_amount' => $wallet->blocked_amount
            ]);

            return [
                'success' => true,
                'withdrawal_request_id' => $withdrawalRequestId,
                'status' => 'cancelled',
                'message' => 'Demande de retrait annulée avec succès',
                'blocked_amount' => $wallet->blocked_amount
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur lors de l'annulation de la demande de retrait: " . $e->getMessage(), [
                'withdrawal_request_id' => $withdrawalRequestId,
                'cancelled_by' => $cancelledBy
            ]);
            throw $e;
        }
    }

    /**
     * 📋 DEMANDE DE RETRAIT AVEC VALIDATION DES RÔLES - Avec blocked_amount
     */
    public function securedWithdrawalRequest(
        string $fromUserId,
        string $toUserId,
        int $amount,
        ?string $description = null,
        ?array $metadata = null
    ): array {
        return DB::transaction(function () use ($fromUserId, $toUserId, $amount, $description, $metadata) {

            // 🔒 Récupération des utilisateurs et wallets
            $fromWallet = $this->walletRepository->getByUserId($fromUserId);
            $toWallet = $this->walletRepository->getByUserId($toUserId);

            if (!$fromWallet || !$toWallet) {
                throw new ModelNotFoundException("Un des wallets est introuvable");
            }

            $fromUser = $fromWallet->user;
            $toUser = $toWallet->user;

            // 🛡️ VALIDATION DES PERMISSIONS
            $this->validateWithdrawalPermissions($fromUser, $toUser);

            // 🛡️ VÉRIFICATION DES SOLDES (en utilisant blocked_amount)
            $availableBalance = $fromWallet->cash_available - $fromWallet->blocked_amount;
            if ($availableBalance < $amount) {
                throw new Exception("Solde insuffisant pour effectuer le retrait. Disponible: {$availableBalance}, Demandé: {$amount}");
            }

            // 🔄 EXÉCUTION DU RETRAIT
            return $this->withdrawalRequest($fromUserId, $toUserId, $amount, $description, $metadata);
        });
    }

    /**
     * 🛡️ VALIDATION DES PERMISSIONS POUR RETRAIT
     */
    private function validateWithdrawalPermissions($fromUser, $toUser): void
    {
        $fromRole = $fromUser->role->slug;
        $toRole = $toUser->role->slug;

        // ✅ SUPER ADMIN - Peut tout faire
        if ($fromRole === RoleEnum::SUPER_ADMIN) {
            return;
        }

        // ✅ ADMIN FINANCE/SUPPORT/COMMERCIAL - Restrictions
        if (in_array($fromRole, [
            RoleEnum::FINANCE_ADMIN,
            RoleEnum::SUPPORT_ADMIN,
            RoleEnum::COMMERCIAL_ADMIN
        ])) {
            // Vérifier que l'admin ne crédite que ses PROs assignés
            if ($toRole === RoleEnum::PRO && $toUser->assigned_user !== $fromUser->id) {
                throw new Exception("Vous ne pouvez effectuer des retraits que vers les PROs qui vous sont assignés");
            }
            return;
        }

        // ✅ PRO - Ne peut retirer que vers ses comptes assignés
        if ($fromRole === RoleEnum::PRO) {
            if ($toUser->id !== $fromUser->id && $toUser->assigned_user !== $fromUser->id) {
                throw new Exception("Vous ne pouvez effectuer des retraits que vers vos comptes assignés");
            }
            return;
        }

        // ❌ Rôle non autorisé
        throw new Exception("Votre rôle ne vous permet pas d'effectuer cette opération");
    }

    /**
     * Récupérer le solde disponible (en excluant les fonds bloqués)
     */
    public function getAvailableWithdrawalBalance(string $userId): int
    {
        $wallet = $this->walletRepository->getByUserId($userId);
        if (!$wallet) {
            return 0;
        }

        return $wallet->cash_available - $wallet->blocked_amount;
    }

    /**
     * Vérifier si une demande de retrait peut être créée
     */
    public function canCreateWithdrawalRequest(string $userId, int $amount): bool
    {
        $wallet = $this->walletRepository->getByUserId($userId);
        if (!$wallet) {
            return false;
        }

        $availableBalance = $wallet->cash_available - $wallet->blocked_amount;
        return $availableBalance >= $amount;
    }

    /**
     * 📊 HISTORIQUE DES DEMANDES DE RETRAIT
     */
    public function getWithdrawalRequests(string $userId, ?array $filters = null)
    {
        $query = $this->withdrawalRequestRepository->getQuery()
            ->where('user_id', $userId);

        // Filtres optionnels
        if ($filters) {
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (isset($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }
            if (isset($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }
            if (isset($filters['min_amount'])) {
                $query->where('amount', '>=', $filters['min_amount']);
            }
            if (isset($filters['max_amount'])) {
                $query->where('amount', '<=', $filters['max_amount']);
            }
        }

        return $query->with(['wallet', 'processor'])
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * 📈 STATISTIQUES DES RETRAITS
     */
    public function getWithdrawalStats(string $userId, ?string $period = 'month')
    {
        $startDate = match ($period) {
            'day' => now()->subDay(),
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };

        $stats = $this->withdrawalRequestRepository->getQuery()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_requests,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount,
                MIN(amount) as min_amount,
                MAX(amount) as max_amount
            ')
            ->first();

        // Statistiques par statut
        $statusStats = $this->withdrawalRequestRepository->getQuery()
            ->where('user_id', $userId)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('status, COUNT(*) as count, SUM(amount) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'period' => $period,
            'total_requests' => $stats->total_requests ?? 0,
            'total_amount' => $stats->total_amount ?? 0,
            'average_amount' => $stats->average_amount ?? 0,
            'min_amount' => $stats->min_amount ?? 0,
            'max_amount' => $stats->max_amount ?? 0,
            'by_status' => $statusStats,
            'period_start' => $startDate->toISOString(),
            'period_end' => now()->toISOString(),
        ];
    }


    private function resolveCommissionPayer(): User
    {
        $superAdmin = User::whereHas('role', function ($q) {
            $q->where('is_super_admin', true);
        })->first();

        if (!$superAdmin) {
            throw new \Exception("Super Admin introuvable pour le paiement des commissions");
        }

        return $superAdmin;
    }




    /**
     * ✅ Retrait avec gestion PRO / CLIENT
     * - PRO : retrait vendeur + commission payée par Super Admin au vendeur
     * - CLIENT : paiement direct par Super Admin, aucune commission
     */
    public function withdrawPayment(
        string $walletId,
        string $userId,
        int $amount,
        string $provider,
        ?string $description = null
    ): bool {

        return DB::transaction(function () use ($walletId, $userId, $amount, $provider, $description) {

            $seller = User::findOrFail($userId);
            $superAdmin = $this->resolveCommissionPayer();

            // 🔒 Lock wallets
            $sellerWallet = $this->walletRepository->findForUpdate($walletId);
            if (!$sellerWallet) {
                throw new ModelNotFoundException("Wallet vendeur introuvable");
            }

            $superAdminWallet = $this->walletRepository->findForUpdate($superAdmin->wallet->id);

            // 🔍 Récupérer les floats
            //$sellerFloat = $sellerWallet->floats()->where('provider', $provider)->first();

            $sellerFloat = $this->getOrCreateFloat($sellerWallet, $provider, 0.00);

            // if (!$sellerFloat) {
            //     throw new Exception("Float {$provider} introuvable pour le vendeur");
            // }

            $reference = uniqid('txn_withdrawal_');

            // 🔍 DEBUG: Log pour voir les valeurs
            Log::info('Début retrait', [
                'user_id' => $userId,
                'user_type' => $seller->isPro() ? 'PRO' : 'CLIENT',
                'amount' => $amount,
                'provider' => $provider,
                'wallet_seller_id' => $sellerWallet->id,
                'seller_float_rate' => $sellerFloat->rate,
                'wallet_admin_id' => $superAdminWallet->id ?? null,
            ]);

            /**
             * ===============================
             * 🔹 CAS 1 : UTILISATEUR PRO
             * ===============================
             */
            if ($seller->isPro()) {

                // ✅ Vérifier solde vendeur pour le retrait principal
                $sellerAvailable =
                    $sellerWallet->cash_available - $sellerWallet->blocked_amount;

                if ($sellerAvailable < $amount) {
                    throw new \Exception(
                        "Solde vendeur insuffisant. Disponible: $sellerAvailable, Demandé: $amount"
                    );
                }

                // 🔻 Débit vendeur (montant principal)
                $this->walletRepository->withdraw($sellerWallet->id, $amount);

                // 📜 Transaction retrait vendeur
                $this->transactionRepository->create([
                    'wallet_id'   => $sellerWallet->id,
                    'user_id'     => $seller->id,
                    'amount'      => -$amount,
                    'type'        => 'withdrawal',
                    'reference'   => $reference,
                    'description' => $description ?? "Retrait de $amount GNF via $provider",
                    'metadata'    => [
                        'provider' => $provider,
                        'user_type' => 'pro',
                    ],
                ]);

                // 🔹 Commission PAYÉE PAR SUPER ADMIN AU VENDEUR
                try {
                    // Récupérer la commission
                    // $commissionRecord = $this->commissionRepository->getByKey($provider);

                    $commissionRate = $sellerFloat->rate;
                    $commissionAmount = (int) round($amount * $commissionRate);

                    // 🔍 DEBUG: Log la commission
                    Log::info('Calcul commission depuis float', [
                        'amount' => $amount,
                        'commissionRate' => $commissionRate,
                        'commissionAmount' => $commissionAmount,
                        'float_id' => $sellerFloat->id
                    ]);



                    // Vérifier si la commission est > 0
                    if ($commissionAmount > 0) {
                        // Vérifier solde Super Admin pour payer la commission
                        $adminAvailable = $superAdminWallet->cash_available - $superAdminWallet->blocked_amount;

                        Log::info('Solde Super Admin', [
                            'adminAvailable' => $adminAvailable,
                            'commissionAmount' => $commissionAmount,
                            'superAdminWallet' => $superAdminWallet->id,
                        ]);

                        if ($adminAvailable < $commissionAmount) {
                            throw new \Exception(
                                "Solde Super Admin insuffisant pour payer la commission. " .
                                    "Disponible: $adminAvailable, Commission: $commissionAmount"
                            );
                        }

                        // 🔻 Débit Super Admin (il paie la commission)
                        $this->walletRepository->withdraw(
                            $superAdminWallet->id,
                            $commissionAmount
                        );

                        // 🔺 Crédit vendeur (reçoit la commission)
                        $sellerWallet->commission_balance += $commissionAmount;
                        $sellerWallet->commission_available += $commissionAmount;
                        $sellerWallet->save();

                        // 🔺 Crédit vendeur (reçoit la commission) - Float uniquement
                        // $sellerFloat->commission += $commissionAmount;
                        // $sellerFloat->save();

                        $seller->commission_portefeuille += $commissionAmount;
                        $seller->save();

                        // 📜 Transaction commission reçue par vendeur
                        $this->transactionRepository->create([
                            'wallet_id' => $sellerWallet->id,
                            'user_id'   => $seller->id,
                            'amount'    => $commissionAmount,
                            'type'      => 'commission_received',
                            'reference' => $reference,
                            'description' =>
                            "Commission reçue (" . ($commissionRate * 100) . "%)",
                            'metadata'  => [
                                'provider' => $provider,
                                'payer_id' => $superAdmin->id,
                                'float_id' => $sellerFloat->id,
                                'commission_before' => $sellerFloat->commission - $commissionAmount,
                                'commission_after' => $sellerFloat->commission,
                            ],
                        ]);

                        // 📜 Transaction commission payée par Super Admin
                        $this->transactionRepository->create([
                            'wallet_id' => $superAdminWallet->id,
                            'user_id'   => $superAdmin->id,
                            'amount'    => -$commissionAmount,
                            'type'      => 'commission_paid',
                            'reference' => $reference,
                            'description' =>
                            "Commission payée à {$seller->display_name}",
                            'metadata'  => [
                                'receiver_id' => $seller->id,
                                'provider' => $provider,
                                'float_id' => $superAdminWallet->id,
                                'wallet_balance_before' => $superAdminWallet->cash_available + $commissionAmount,
                                'wallet_balance_after' => $superAdminWallet->cash_available,
                            ],
                        ]);

                        Log::info('Commission traitée avec succès', [
                            'commissionAmount' => $commissionAmount,
                            'seller_id' => $seller->id,
                            'superAdmin_id' => $superAdmin->id,
                        ]);
                    } else {
                        Log::info('Commission non appliquée (montant = 0)', [
                            'commissionAmount' => $commissionAmount,
                            'amount' => $amount,
                            'commissionRate' => $commissionRate,
                            'float_rate' => $commissionRate,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Erreur lors du traitement de la commission', [
                        'error' => $e->getMessage(),
                        'provider' => $provider,
                        'seller_id' => $seller->id,
                        'seller_float_id' => $sellerFloat->id,
                    ]);
                    throw $e; // ou continuez sans commission selon votre logique métier
                }

                /**
                 * ===============================
                 * 🔹 CAS 2 : CLIENT SIMPLE
                 * ===============================
                 */
            } else {

                // 🔻 Super Admin paie le client (montant complet)
                $adminAvailable =
                    $superAdminWallet->cash_available - $superAdminWallet->blocked_amount;

                if ($adminAvailable < $amount) {
                    throw new \Exception(
                        "Solde Super Admin insuffisant pour payer le client"
                    );
                }

                // 🔻 Débit Super Admin
                $this->walletRepository->withdraw(
                    $superAdminWallet->id,
                    $amount
                );

                // 📜 Transaction paiement client
                $this->transactionRepository->create([
                    'wallet_id'   => $superAdminWallet->id,
                    'user_id'     => $superAdmin->id,
                    'amount'      => -$amount,
                    'type'        => 'client_payment',
                    'reference'   => $reference,
                    'description' =>
                    $description ?? "Paiement client $amount GNF via $provider",
                    'metadata'    => [
                        'client_id' => $seller->id,
                        'provider' => $provider,
                        'user_type' => 'client',
                        'wallet_balance_before' => $superAdminWallet->cash_available + $amount,
                        'wallet_balance_after' => $superAdminWallet->cash_available,
                    ],
                ]);
            }

            return true;
        });
    }


    /**
     * ✅ Dépôt (remboursement) - Inverse exact de withdrawPayment
     * - PRO : est crédité du montant de la vente + débité de la commission
     * - CLIENT : Super Admin est remboursé du montant
     */
    public function depositPayment(
        string $walletId,
        string $userId,
        int $amount,
        string $provider,
        ?string $description = null
    ): bool {
        return DB::transaction(function () use ($walletId, $userId, $amount, $provider, $description) {
            try {
                $user = User::findOrFail($userId);
                $superAdmin = $this->resolveCommissionPayer();

                // 🔒 Lock wallets
                $wallet = $this->walletRepository->findForUpdate($walletId);
                if (!$wallet) {
                    throw new ModelNotFoundException("Wallet introuvable");
                }

                $superAdminWallet = $this->walletRepository->findForUpdate($superAdmin->wallet->id);

                // 🔍 Récupérer les floats
                //$userFloat = $wallet->floats()->where('provider', $provider)->first();

                $userFloat = $this->getOrCreateFloat($wallet, $provider, 0.01);

                // if (!$userFloat) {
                //     throw new \Exception("Float {$provider} introuvable pour l'utilisateur");
                // }

                $reference = uniqid('txn_deposit_');


                Log::info('🔄 Début dépôt (remboursement) avec floats', [
                    'user_id' => $userId,
                    'user_role' => $user->role->slug,
                    'amount' => $amount,
                    'provider' => $provider,
                    'wallet_id' => $wallet->id,
                    'user_float_rate' => $userFloat->rate,
                    'user_float_commission' => $userFloat->commission,
                ]);

                /**
                 * ===============================
                 * 🔹 CAS 1 : UTILISATEUR PRO (REMBOURSEMENT)
                 * ===============================
                 */
                if ($user->role->slug == RoleEnum::PRO) {
                    Log::info('🔹 Remboursement PRO', [
                        'pro_id' => $user->id,
                        'super_admin_id' => $superAdmin->id,
                        'float_id' => $userFloat->id
                    ]);

                    // 🧮 Calcul de la commission (même calcul que dans withdrawPayment)
                    // $commissionRate = $this->getCommissionRate($provider);
                    // $commissionAmount = (int) round($amount * $commissionRate);

                    // 🧮 Calcul de la commission depuis le float du PRO
                    $commissionRate = $userFloat->rate;
                    $commissionAmount = (int) round($amount * $commissionRate);

                    Log::info('📊 Calcul commission remboursement', [
                        'amount' => $amount,
                        'commission_rate' => $commissionRate,
                        'commission_amount' => $commissionAmount,
                        'float_commission_before' => $userFloat->commission
                    ]);

                    // 1. ✅ CRÉDITER LE PRO DU MONTANT DE LA VENTE - Wallet et Float
                    // $this->walletRepository->credit($wallet->id, $amount);
                    // $userFloat->balance += $amount;
                    // $userFloat->save();

                    // 1. ✅ CRÉDITER LE PRO DU MONTANT DE LA VENTE
                    // (Inverse de withdrawPayment qui le débitait)
                    $this->walletRepository->credit($wallet->id, $amount);

                    // 📜 Transaction crédit remboursement vente
                    $this->transactionRepository->create([
                        'wallet_id' => $wallet->id,
                        'user_id' => $user->id,
                        'amount' => $amount,
                        'type' => 'refund_sale_credit',
                        'reference' => $reference,
                        'description' => $description ?? "Remboursement vente $amount GNF via $provider",
                        'metadata' => [
                            'provider' => $provider,
                            'user_type' => 'pro_refund',
                            'commission_rate' => $commissionRate,
                            'wallet_balance_before' => $wallet->cash_available - $amount,
                            'wallet_balance_after' => $wallet->cash_available,
                        ],
                    ]);

                    Log::info('✅ PRO crédité du montant de la vente', [
                        'pro_id' => $user->id,
                        'amount' => $amount,
                        'new_wallet_balance' => $wallet->cash_available
                    ]);

                    // 2. ✅ DÉBITER LE PRO DE LA COMMISSION
                    // (Inverse de withdrawPayment qui le créditait de la commission)
                    if ($commissionAmount > 0) {
                        // Vérifier que le PRO a assez de commission à rembourser
                        if ($wallet->commission_available < $commissionAmount) {
                            throw new \Exception(
                                "Commission insuffisante à rembourser. " .
                                    "Disponible: {$wallet->commission_available}, À rembourser: $commissionAmount"
                            );
                        }

                        // Débiter la commission du float du PRO
                        // $userFloat->commission -= $commissionAmount;
                        // $userFloat->save();

                        // Débiter la commission du PRO
                        $wallet->commission_available -= $commissionAmount;
                        $wallet->commission_balance -= $commissionAmount;
                        $wallet->save();

                        $user->commission_portefeuille -= $commissionAmount;
                        $user->save();

                        // 📜 Transaction débit commission
                        $this->transactionRepository->create([
                            'wallet_id' => $wallet->id,
                            'user_id' => $user->id,
                            'amount' => -$commissionAmount,
                            'type' => 'commission_refunded',
                            'reference' => $reference,
                            'description' => "Commission remboursée (" . ($commissionRate * 100) . "%)",
                            'metadata' => [
                                'provider' => $provider,
                                'refunded_to' => $superAdmin->id,
                                'sale_amount' => $amount,
                                'commission_rate' => $commissionRate,
                                'wallet_solde_before' => $wallet->cash_available + $commissionAmount,
                                'wallet_solde_after' => $wallet->cash_available,
                            ],
                        ]);

                        // 3. ✅ CRÉDITER LE SUPER ADMIN DE LA COMMISSION
                        // (Il récupère la commission qu'il avait payée)
                        $this->walletRepository->credit($superAdminWallet->id, $commissionAmount);

                        // 📜 Transaction remboursement commission à Super Admin
                        $this->transactionRepository->create([
                            'wallet_id' => $superAdminWallet->id,
                            'user_id' => $superAdmin->id,
                            'amount' => $commissionAmount,
                            'type' => 'commission_refund_received',
                            'reference' => $reference,
                            'description' => "Commission remboursée par {$user->display_name}",
                            'metadata' => [
                                'pro_id' => $user->id,
                                'provider' => $provider,
                                'sale_amount' => $amount,
                                'commission_rate' => $commissionRate,
                                'wallet_balance_before' => $superAdminWallet->cash_available - $commissionAmount,
                                'wallet_balance_after' => $superAdminWallet->cash_available,
                            ],
                        ]);

                        Log::info('✅ Commission remboursée au Super Admin', [
                            'pro_id' => $user->id,
                            'commission_amount' => $commissionAmount,
                            'super_admin_id' => $superAdmin->id,
                            'new_superadmin_solde_balance' => $superAdminWallet->cash_available
                        ]);
                    }

                    Log::info('✅ Remboursement PRO terminé', [
                        'pro_id' => $user->id,
                        'sale_amount_credited' => $amount,
                        'commission_debited' => $commissionAmount,
                        'net_effect' => $amount - $commissionAmount,
                        'final_user_wallet_balance' => $wallet->cash_available,
                        'final_user_wallet_commission' => $wallet->commission_available,
                    ]);

                    /**
                     * ===============================
                     * 🔹 CAS 2 : CLIENT SIMPLE (REMBOURSEMENT)
                     * ===============================
                     */
                } elseif ($user->role->slug == RoleEnum::CLIENT) {
                    Log::info('🔹 Remboursement CLIENT', [
                        'client_id' => $user->id
                    ]);

                    // 🔺 Super Admin est remboursé du montant qu'il avait payé
                    $this->walletRepository->credit($superAdminWallet->id, $amount);

                    // 📜 Transaction remboursement à Super Admin
                    $this->transactionRepository->create([
                        'wallet_id' => $superAdminWallet->id,
                        'user_id' => $superAdmin->id,
                        'amount' => $amount,
                        'type' => 'client_refund_received',
                        'reference' => $reference,
                        'description' => "Remboursement client $amount GNF via $provider",
                        'metadata' => [
                            'client_id' => $user->id,
                            'provider' => $provider,
                            'user_type' => 'client_refund',
                            'superadmin_wallet_balance_before' => $superAdminWallet->cash_available - $amount,
                            'superadmin_wallet_balance_after' => $superAdminWallet->cash_available,
                        ],
                    ]);

                    Log::info('✅ Super Admin remboursé pour client', [
                        'client_id' => $user->id,
                        'amount' => $amount,
                        'super_admin_id' => $superAdmin->id,
                        'new_superadmin_wallet_balance' => $superAdminWallet->cash_available,
                        'new_client_float_balance' => $userFloat->balance,
                    ]);

                    /**
                     * ===============================
                     * 🔹 CAS 3 : AUTRES RÔLES
                     * ===============================
                     */
                } else {
                    Log::info('🔹 Dépôt autre rôle', [
                        'user_id' => $user->id,
                        'role' => $user->role->slug
                    ]);

                    // Simple crédit pour les autres rôles
                    $this->walletRepository->credit($wallet->id, $amount);

                    // 📜 Transaction
                    $this->transactionRepository->create([
                        'wallet_id' => $wallet->id,
                        'user_id' => $user->id,
                        'amount' => $amount,
                        'type' => 'deposit',
                        'reference' => $reference,
                        'description' => $description ?? "Dépôt de $amount GNF via $provider",
                        'metadata' => [
                            'provider' => $provider,
                            'user_role' => $user->role->slug,

                        ],
                    ]);

                    Log::info('✅ Dépôt autre rôle terminé', [
                        'user_id' => $user->id,
                        'amount' => $amount,
                    ]);
                }

                return true;
            } catch (\Exception $e) {
                Log::error('❌ Erreur lors du dépôt (remboursement): ' . $e->getMessage(), [
                    'wallet_id' => $walletId,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'provider' => $provider,
                ]);
                throw $e;
            }
        });
    }



    /**
     * ✅ Retrait DML selon le type d'utilisateur
     */
    public function withdrawDmlPayment(int $amount, string $provider, User $user): void
    {
        DB::transaction(function () use ($amount, $provider, $user) {
            try {
                // Récupérer le super admin
                $superAdmin = $this->getSuperAdmin();
                $wallet = $this->walletRepository->getByUserId($user->id);

                if (!$wallet) {
                    throw new \Exception("Wallet introuvable pour l'utilisateur");
                }

                Log::info('💰 Début retrait DML', [
                    'user_id' => $user->id,
                    'user_role' => $user->role->slug,
                    'amount' => $amount,
                    'provider' => $provider,
                    'wallet_id' => $wallet->id
                ]);

                /**
                 * ===============================
                 * 🔹 CAS 1 : UTILISATEUR PRO
                 * ===============================
                 */
                if ($user->role->slug == RoleEnum::PRO) {
                    Log::info('🔹 Retrait PRO DML', [
                        'pro_id' => $user->id,
                        'super_admin_id' => $superAdmin->id
                    ]);

                    // Utiliser la méthode existante withdrawPayment qui gère déjà la commission
                    $this->withdrawPayment(
                        $wallet->id,
                        $user->id,
                        $amount,
                        $provider,
                        "Retrait DML - Transaction via $provider"
                    );

                    Log::info('✅ Retrait PRO DML terminé', [
                        'pro_id' => $user->id,
                        'amount' => $amount,
                        'provider' => $provider
                    ]);

                    /**
                     * ===============================
                     * 🔹 CAS 2 : CLIENT
                     * ===============================
                     */
                } elseif ($user->role->slug == RoleEnum::CLIENT) {
                    Log::info('🔹 Retrait CLIENT DML', [
                        'client_id' => $user->id,
                        'assigned_pro_id' => $user->assigned_user
                    ]);

                    // Pour les clients, on utilise withdrawPayment avec le super admin comme payeur
                    // Le super admin paie directement pour le client
                    $this->withdrawPayment(
                        $superAdmin->wallet->id,  // Wallet du super admin
                        $superAdmin->id,           // ID du super admin comme payeur
                        $amount,
                        $provider,
                        "Paiement DML pour client {$user->id} via $provider"
                    );

                    Log::info('✅ Paiement client DML terminé', [
                        'client_id' => $user->id,
                        'super_admin_id' => $superAdmin->id,
                        'amount' => $amount
                    ]);

                    /**
                     * ===============================
                     * 🔹 CAS 3 : AUTRES RÔLES
                     * ===============================
                     */
                } else {
                    Log::info('🔹 Retrait autre rôle DML', [
                        'user_id' => $user->id,
                        'role' => $user->role->slug
                    ]);

                    // Pour les autres rôles, retrait normal
                    $this->withdrawPayment(
                        $wallet->id,
                        $user->id,
                        $amount,
                        $provider,
                        "Retrait DML via $provider"
                    );
                }

                Log::info('✅ Retrait DML terminé avec succès', [
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'provider' => $provider
                ]);
            } catch (\Exception $e) {
                Log::error('❌ Erreur lors du retrait DML: ' . $e->getMessage(), [
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'provider' => $provider
                ]);
                throw $e;
            }
        });
    }

    /**
     * ✅ Remboursement DML selon le type d'utilisateur
     */
    public function refundPayment(int $amount, string $provider, User $user): void
    {
        DB::transaction(function () use ($amount, $provider, $user) {
            try {
                // Récupérer le super admin
                $superAdmin = $this->getSuperAdmin();
                $wallet = $this->walletRepository->getByUserId($user->id);

                if (!$wallet) {
                    throw new \Exception("Wallet introuvable pour l'utilisateur");
                }

                Log::info('🔄 Début remboursement DML', [
                    'user_id' => $user->id,
                    'user_role' => $user->role->slug,
                    'amount' => $amount,
                    'provider' => $provider
                ]);

                /**
                 * ===============================
                 * 🔹 CAS 1 : UTILISATEUR PRO
                 * ===============================
                 */
                if ($user->role->slug == RoleEnum::PRO) {
                    Log::info('🔹 Remboursement PRO DML', [
                        'pro_id' => $user->id,
                        'super_admin_id' => $superAdmin->id
                    ]);

                    // Utiliser la méthode existante depositPayment qui gère déjà le remboursement de commission
                    $this->depositPayment(
                        $wallet->id,
                        $user->id,
                        $amount,
                        $provider,
                        "Remboursement DML - Transaction via $provider"
                    );

                    Log::info('✅ Remboursement PRO DML terminé', [
                        'pro_id' => $user->id,
                        'amount' => $amount
                    ]);

                    /**
                     * ===============================
                     * 🔹 CAS 2 : CLIENT
                     * ===============================
                     */
                } elseif ($user->role->slug == RoleEnum::CLIENT) {
                    Log::info('🔹 Remboursement CLIENT DML', [
                        'client_id' => $user->id
                    ]);

                    // Pour les clients, le super admin est remboursé
                    $this->depositPayment(
                        $superAdmin->wallet->id,
                        $superAdmin->id,
                        $amount,
                        $provider,
                        "Remboursement DML pour client {$user->id}"
                    );

                    Log::info('✅ Remboursement client DML terminé', [
                        'client_id' => $user->id,
                        'amount' => $amount,
                        'super_admin_id' => $superAdmin->id
                    ]);

                    /**
                     * ===============================
                     * 🔹 CAS 3 : AUTRES RÔLES
                     * ===============================
                     */
                } else {
                    Log::info('🔹 Remboursement autre rôle DML', [
                        'user_id' => $user->id,
                        'role' => $user->role->slug
                    ]);

                    // Pour les autres rôles, remboursement normal
                    $this->depositPayment(
                        $wallet->id,
                        $user->id,
                        $amount,
                        $provider,
                        "Remboursement DML via $provider"
                    );
                }

                Log::info('✅ Remboursement DML terminé avec succès', [
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'provider' => $provider
                ]);
            } catch (\Exception $e) {
                Log::error('❌ Erreur lors du remboursement DML: ' . $e->getMessage(), [
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'provider' => $provider
                ]);
                throw $e;
            }
        });
    }

    /**
     * ✅ Retirer depuis le wallet d'un utilisateur (version simplifiée)
     */
    private function withdrawFromUserWallet(string $userId, int $amount, string $provider): void
    {
        $wallet = $this->walletRepository->getByUserId($userId);

        if (!$wallet) {
            throw new \Exception("Wallet introuvable pour l'utilisateur: $userId");
        }

        // Déléguer à withdrawPayment qui gère déjà toute la logique
        $this->withdrawPayment(
            $wallet->id,
            $userId,
            $amount,
            $provider,
            'Retrait pour transaction DML'
        );
    }

    /**
     * ✅ Rembourser vers le wallet d'un utilisateur
     */
    private function refundToUserWallet(string $userId, int $amount, string $provider): void
    {
        $wallet = $this->walletRepository->getByUserId($userId);

        if (!$wallet) {
            throw new \Exception("Wallet introuvable pour l'utilisateur: $userId");
        }

        // Déléguer à depositPayment qui gère déjà toute la logique
        $this->depositPayment(
            $wallet->id,
            $userId,
            $amount,
            $provider,
            'Remboursement suite à échec de transaction DML'
        );
    }

    /**
     * ✅ Obtenir le taux de commission
     */
    private function getCommissionRate(string $provider): float
    {
        $commissionSetting = $this->commissionRepository->getByKey($provider);

        if (!$commissionSetting) {
            Log::warning("Aucune commission trouvée pour le provider: $provider, utilisation de 2% par défaut");
            return 0.0; // 0% par défaut
        }

        return (float) $commissionSetting->value;
    }

    /**
     * ✅ Obtenir le super admin
     */
    private function getSuperAdmin(): User
    {
        $superAdmin = User::whereHas('role', function ($query) {
            $query->where('slug', RoleEnum::SUPER_ADMIN);
        })->first();

        if (!$superAdmin) {
            throw new \Exception('Super admin non trouvé');
        }

        return $superAdmin;
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
