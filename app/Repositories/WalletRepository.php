<?php

namespace App\Repositories;

use App\Enums\CommissionEnum;
use App\Enums\RoleEnum;
use App\Helpers\HelperStatus;
use App\Interfaces\WalletRepositoryInterface;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFloat;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletRepository implements WalletRepositoryInterface
{
    /**
     * Authenticated User Instance.
     *
     * @var User|null
     */
    public ?User $user;


    /**
     * @var array Rôles par défaut pour les commissions
     */
    protected array $defaultCommissions = [
        CommissionEnum::EDG,
        CommissionEnum::GSS,
        CommissionEnum::SOUS_ADMIN
    ];


    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->user = Auth::guard()->user();
    }

    public function getAll(): iterable
    {
        try {
            if ($this->user->role->slug === RoleEnum::SUPER_ADMIN) {
                // Super-admin ou admin : retourne tous les wallets
                return Wallet::with(['user', 'floats', 'wallet_transactions'])->get();
            } else {
                // Sous-admin : retourne seulement les wallets de leurs utilisateurs assignés
                return Wallet::with(['user', 'floats', 'wallet_transactions'])
                    ->whereHas('user', function ($query) {
                        $query->where('assigned_user', $this->user->id)
                            ->whereNull('deleted_at'); // exclut les users supprimés
                    })
                    ->get();
            }
        } catch (QueryException $e) {
            // Log de l'erreur pour debug
            logger()->error("Erreur lors de la récupération de tous les wallets : " . $e->getMessage());
            return [];
        }
    }



    public function getById(string $id): ?Wallet
    {
        try {
            return Wallet::with(['user', 'floats', 'wallet_transactions'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            logger()->warning("Wallet non trouvé avec l'ID : $id");
            return null;
        } catch (Exception $e) {
            logger()->error("Erreur lors de la récupération du wallet $id : " . $e->getMessage());
            return null;
        }
    }

    public function create(array $data): ?Wallet
    {

        DB::beginTransaction();
        try {
            // Créer le wallet d'abord
            $wallet = Wallet::create($data);


            // Créer les floats par défaut avec les bons types
            $defaultFloats = [
                ['provider' => CommissionEnum::EDG, 'balance' => 0, 'commission' => 0, 'rate' => 0.01],
                ['provider' => CommissionEnum::GSS, 'balance' => 0, 'commission' => 0, 'rate' => 0.01],
                ['provider' => CommissionEnum::SOUS_ADMIN, 'balance' => 0, 'commission' => 0, 'rate' => 0.01],
            ];

            foreach ($defaultFloats as $float) {
                $this->addFloat(
                    $wallet->id,
                    $float['provider'],
                    $float['balance'],
                    $float['commission'],
                    $float['rate']
                );
            }



            DB::commit();
            return $wallet;
        } catch (QueryException $e) {
            DB::rollBack();
            logger()->error("Erreur SQL lors de la création d'un wallet : " . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            DB::rollBack();
            logger()->error("Erreur générale lors de la création du wallet : " . $e->getMessage());
            return null;
        }
    }

    public function update(string $id, array $data): bool
    {
        try {
            $wallet = Wallet::findOrFail($id);
            return $wallet->update($data);
        } catch (ModelNotFoundException $e) {
            logger()->warning("Impossible de mettre à jour, wallet non trouvé avec ID : $id");
            return false;
        } catch (QueryException $e) {
            logger()->error("Erreur SQL lors de la mise à jour du wallet $id : " . $e->getMessage());
            return false;
        }
    }

    public function updateFloatRate(string $id, float $rate): bool
    {
        try {
            return WalletFloat::where('id', $id)->update([
                'rate' => $rate
            ]) > 0;
        } catch (ModelNotFoundException $e) {
            logger()->warning("Impossible de mettre à jour, wallet flotte non trouvé avec ID : $id");
            return false;
        } catch (QueryException $e) {
            logger()->error("Erreur SQL lors de la mise à jour du wallet flotte $id : " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $id): bool
    {
        try {
            $wallet = Wallet::findOrFail($id);
            return (bool) $wallet->delete();
        } catch (ModelNotFoundException $e) {
            logger()->warning("Impossible de supprimer, wallet non trouvé avec ID : $id");
            return false;
        } catch (Exception $e) {
            logger()->error("Erreur lors de la suppression du wallet $id : " . $e->getMessage());
            return false;
        }
    }

    public function findByUser(string $userId): iterable
    {
        try {
            return Wallet::with(['user', 'floats', 'wallet_transactions'])->where('user_id', $userId)->latest()->get();
        } catch (QueryException $e) {
            logger()->error("Erreur SQL lors de la récupération des wallets de l'utilisateur $userId : " . $e->getMessage());
            return collect();
        }
    }

    public function getFloatByWalletAndProvider(string $walletId, string $provider): ?WalletFloat
    {
        try {
            return WalletFloat::with('wallet')->where('wallet_id', $walletId)
                ->where('provider', $provider)
                ->first();
        } catch (QueryException $e) {
            logger()->error("Erreur SQL lors de la récupération du float $provider du wallet $walletId : " . $e->getMessage());
            return null;
        }
    }


    public function updateBalance(string $walletId, int $amount): bool
    {
        try {
            $wallet = Wallet::findOrFail($walletId);

            $availableBalance = $wallet->cash_available - $wallet->blocked_amount;
            if ($availableBalance < $amount) {
                logger()->warning("Fonds insuffisants pour wallet $walletId. Solde actuel : {$wallet->cash_available}, retrait demandé : $amount");
                return false;
            }

            $wallet->cash_available += $amount;
            return $wallet->save();
        } catch (ModelNotFoundException $e) {
            logger()->warning("Wallet non trouvé pour updateBalance, ID : $walletId");
            return false;
        } catch (Exception $e) {
            logger()->error("Erreur lors de l'updateBalance sur wallet $walletId : " . $e->getMessage());
            return false;
        }
    }



    public function addCommission(string $walletId, string $userId, float $commission): bool
    {
        try {
            // 🔒 Verrouille le wallet pendant la transaction
            $wallet = Wallet::with('user')->lockForUpdate()->findOrFail($walletId);

            // // 🔍 Vérifie le float pour le provider
            // $float = $wallet->floats()->where('provider', $provider)->first();
            // if (!$float) {
            //     throw new Exception("Float non trouvé pour le provider {$provider}");
            // }

            // // 💰 Mise à jour du float (ajout de la commission)
            // $float->commission += $commission;
            // $float->save();

            // 💼 Mise à jour des totaux du wallet
            $wallet->commission_available += $commission;
            $wallet->commission_balance += $commission;
            $wallet->save();

            return true;
        } catch (ModelNotFoundException $e) {
            logger()->warning("⚠️ Wallet non trouvé pour addCommission, ID : {$walletId}");
            return false;
        } catch (Exception $e) {
            logger()->error("💥 Erreur lors de addCommission sur wallet {$walletId} : " . $e->getMessage());
            return false;
        }
    }


    public function withdraw(string $walletId, int $amount): bool
    {
        try {
            $wallet = Wallet::find($walletId);
            if (!$wallet) {
                return false;
            }

            // Vérification du solde seulement pour le débit
            $availableBalance = $wallet->cash_available - $wallet->blocked_amount;
            if ($availableBalance < $amount) {
                logger()->warning("Fonds insuffisants pour wallet $walletId. Solde disponible : $availableBalance, retrait demandé : $amount");
                return false;
            }

            // Débit du wallet
            $wallet->cash_available -= $amount;
            $walletSaved = $wallet->save();

            // ✅ Mise à jour du solde dans la table users
            $user = $wallet->user;
            $user->solde_portefeuille -= $amount;
            if ($user->solde_portefeuille < 0) {
                $user->solde_portefeuille = 0; // sécurité
            }
            $userSaved = $user->save();

            return $walletSaved && $userSaved;
        } catch (ModelNotFoundException $e) {
            logger()->warning("Wallet non trouvé pour withdraw, ID : $walletId");
            return false;
        } catch (Exception $e) {
            logger()->error("Erreur lors du withdraw sur wallet $walletId : " . $e->getMessage());
            return false;
        }
    }

    public function credit(string $walletId, float $amount): bool
    {
        $wallet = Wallet::find($walletId);
        if (!$wallet) {
            return false;
        }

        $wallet->cash_available += $amount;
        $user = $wallet->user;
        $user->solde_portefeuille += $amount;
        return $wallet->save() && $user->save();
    }

    /**
     * Recharge le wallet d'un superadmin
     */
    public function rechargeSuperAdmin(string $walletId, int $amount, ?string $description = null): bool
    {
        DB::beginTransaction();

        try {
            // 🔒 Verrouiller le wallet pour éviter les conflits
            $wallet = Wallet::with(['user'])->lockForUpdate()->findOrFail($walletId);

            // Vérifier que l'utilisateur est bien un superadmin
            if ($wallet->user->role->slug !== RoleEnum::SUPER_ADMIN) {
                throw new Exception("Seul un superadmin peut effectuer cette opération");
            }

            // Vérifier que le montant est valide
            if ($amount <= 0) {
                throw new Exception("Le montant doit être supérieur à 0");
            }

            // 🔍 Trouver ou créer le float pour le provider
            // $float = $wallet->floats()->where('provider', $provider)->first();

            // Calcul de la commission
            // $commission = intval($amount * $float->rate);
            // $float->commission += $commission;

            // 💰 Mettre à jour le float
            // $float->balance += $amount;
            // $float->save();

            // 💼 Mettre à jour le wallet
            $wallet->cash_available += $amount;
            // $wallet->commission_available += $commission;
            $wallet->save();

            // 👤 Mettre à jour le solde de l'utilisateur
            $user = $wallet->user;
            $user->solde_portefeuille += $amount;
            // $user->commission_portefeuille += $commission;
            $user->save();

            // 📝 Enregistrer la transaction
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'type' => 'credit_superadmin_recharge',
                'reference'   => uniqid('txn_credit_superadmin_recharge_'),
                // 'provider' => $provider,
                'description' => "Recharge superadmin de {$amount} GNF de $user->display_name",

                'metadata'    => [
                    'balance_before' => $wallet->cash_available - $amount,
                    'balance_after' => $wallet->cash_available,
                    'status' => 'completed',
                    // 'provider' => $provider,
                    'to_user'  => $user->id,
                    'timestamp' => now()->toISOString(),
                ],
            ]);

            // 🎉 Log de succès
            logger()->info("✅ Recharge superadmin réussie", [
                'wallet_id' => $walletId,
                'amount' => $amount,
                // 'provider' => $provider,
                'new_balance' => $wallet->cash_available,
                'user' => $user->name
            ]);

            DB::commit();
            return true;
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            logger()->error("❌ Wallet non trouvé pour recharge superadmin", [
                'wallet_id' => $walletId,
                'error' => $e->getMessage()
            ]);
            return false;
        } catch (Exception $e) {
            DB::rollBack();
            logger()->error("💥 Erreur lors de la recharge superadmin", [
                'wallet_id' => $walletId,
                'amount' => $amount,
                // 'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }



    public function findForUpdate(string $walletId): ?Wallet
    {
        return Wallet::with(['user', 'floats', 'wallet_transactions'])->where('id', $walletId)->lockForUpdate()->first();
    }

    /**
     * Récupérer un wallet par user_id (méthode manquante)
     */
    public function getByUserId(string $userId): ?Wallet
    {
        try {
            return Wallet::with(['user', 'wallet_transactions'])->where('user_id', $userId)->first();
        } catch (Exception $e) {
            logger()->error("Erreur lors de la récupération du wallet pour l'utilisateur $userId : " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ajouter un float à un wallet
     */
    public function addFloat(string $walletId, string $provider, int $balance = 0, int $commission = 0, float $rate): bool
    {
        try {
            $wallet = Wallet::findOrFail($walletId);

            // Vérifier si le float existe déjà
            $existingFloat = $wallet->floats()->where('provider', $provider)->first();

            if ($existingFloat) {
                // Mettre à jour le float existant
                $existingFloat->update([
                    'balance' => $balance,
                    'commission' => $commission
                ]);
                return true;
            } else {
                // Créer un nouveau float
                $float = $wallet->floats()->create([
                    'provider' => $provider,
                    'balance' => $balance,
                    'commission' => $commission
                ]);
                return (bool) $float;
            }
        } catch (ModelNotFoundException $e) {
            logger()->warning("Wallet non trouvé pour addFloat, ID : $walletId");
            return false;
        } catch (Exception $e) {
            logger()->error("Erreur lors de l'ajout du float $provider au wallet $walletId : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer un float d'un wallet
     */
    public function removeFloat(string $walletId, string $provider): bool
    {
        try {
            $wallet = Wallet::findOrFail($walletId);
            $deleted = $wallet->floats()->where('provider', $provider)->delete();
            return $deleted > 0;
        } catch (ModelNotFoundException $e) {
            logger()->warning("Wallet non trouvé pour removeFloat, ID : $walletId");
            return false;
        } catch (Exception $e) {
            logger()->error("Erreur lors de la suppression du float $provider du wallet $walletId : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mettre à jour le solde disponible
     */
    public function updateCashAvailable(string $walletId, int $amount): bool
    {
        try {
            $wallet = Wallet::findOrFail($walletId);
            $wallet->cash_available = $amount;
            return $wallet->save();
        } catch (ModelNotFoundException $e) {
            logger()->warning("Wallet non trouvé pour updateCashAvailable, ID : $walletId");
            return false;
        } catch (Exception $e) {
            logger()->error("Erreur lors de l'updateCashAvailable sur wallet $walletId : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mettre à jour la commission disponible
     */
    public function updateCommissionAvailable(string $walletId, int $commission): bool
    {
        try {
            $wallet = Wallet::findOrFail($walletId);
            $wallet->commission_available = $commission;
            return $wallet->save();
        } catch (ModelNotFoundException $e) {
            logger()->warning("Wallet non trouvé pour updateCommissionAvailable, ID : $walletId");
            return false;
        } catch (Exception $e) {
            logger()->error("Erreur lors de l'updateCommissionAvailable sur wallet $walletId : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Transférer de la commission vers le solde disponible
     */
    public function transferCommissionToBalance(string $walletId, int $amount): bool
    {
        try {
            $wallet = Wallet::findOrFail($walletId);

            if ($wallet->commission_available < $amount) {
                logger()->warning("Commission insuffisante pour le transfert. Wallet: $walletId, Commission disponible: {$wallet->commission_available}, Montant demandé: $amount");
                return false;
            }

            $wallet->commission_available -= $amount;
            $wallet->cash_available += $amount;

            return $wallet->save();
        } catch (ModelNotFoundException $e) {
            logger()->warning("Wallet non trouvé pour transferCommissionToBalance, ID : $walletId");
            return false;
        } catch (Exception $e) {
            logger()->error("Erreur lors du transferCommissionToBalance sur wallet $walletId : " . $e->getMessage());
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
    public function transferWalletBetweenFloats(
        string $walletId,
        string $floatFromId,
        string $floatToId,
        int $amount,
        ?string $description = null
    ): bool {

        try {
            // Validation des paramètres
            if ($amount <= 0) {
                throw new \Exception("Le montant du transfert doit être supérieur à 0");
            }

            if ($floatFromId === $floatToId) {
                throw new \Exception("Impossible de transférer vers le même float");
            }

            // Récupération du wallet avec verrouillage
            $wallet = Wallet::where('id', $walletId)->lockForUpdate()->first();
            if (!$wallet) {
                throw new \Exception("Wallet non trouvé");
            }

            // Récupération des floats avec verrouillage
            // $fromFloat = $this->getFloatByIdForUpdate($floatFromId);
            // $toFloat = $this->getFloatByIdForUpdate($floatToId);

            $fromFloat = $wallet->floats()->where('id', $floatFromId)->first();
            $toFloat = $wallet->floats()->where('id', $floatToId)->first();


            if (!$fromFloat) {
                throw new \Exception("Float émetteur non trouvé");
            }

            if (!$toFloat) {
                throw new \Exception("Float bénéficiaire non trouvé");
            }

            // Vérification du solde suffisant sur le float émetteur
            if ($fromFloat->balance < $amount) {
                throw new \Exception("Solde insuffisant sur le float émetteur");
            }

            return true;
        } catch (\Exception $e) {

            Log::error('Erreur lors du transfert entre floats via wallet: ' . $e->getMessage(), [
                'wallet_id' => $walletId,
                'from_float_id' => $floatFromId,
                'to_float_id' => $floatToId,
                'amount' => $amount,
                'initiated_by' => $this->user->id ?? 'system'
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
        string $formProvider,
        string $toProvider,
        int $amount,
        ?string $description = null
    ): bool {

        try {
            // Validation des paramètres
            if ($amount <= 0) {
                throw new \Exception("Le montant du transfert doit être supérieur à 0");
            }

            if ($formProvider === $toProvider) {
                throw new \Exception("Impossible de transférer vers le même float");
            }

            // Récupération du wallet avec verrouillage
            $wallet = Wallet::where('id', $walletId)->lockForUpdate()->first();
            if (!$wallet) {
                throw new \Exception("Wallet non trouvé");
            }

            $fromFloat = $wallet->floats()->where('provider', $formProvider)->first();
            $toFloat = $wallet->floats()->where('provider', $toProvider)->first();


            if (!$fromFloat) {
                throw new \Exception("Float émetteur non trouvé");
            }

            if (!$toFloat) {
                throw new \Exception("Float bénéficiaire non trouvé");
            }

            // Vérification du solde suffisant sur le float émetteur
            if ($fromFloat->balance < $amount) {
                throw new \Exception("Solde insuffisant sur le float émetteur");
            }

            return true;
        } catch (\Exception $e) {

            Log::error('Erreur lors du transfert entre floats via wallet: ' . $e->getMessage(), [
                'wallet_id' => $walletId,
                'from_float' => $formProvider,
                'to_float' => $toProvider,
                'amount' => $amount,
                'initiated_by' => $this->user->id ?? 'system'
            ]);

            return false;
        }
    }

    public function getFloatByIdForUpdate(string $floatId): ?WalletFloat
    {
        return WalletFloat::where('id', $floatId)->lockForUpdate()->first();
    }

    public function updateFloatBalance(string $floatId, int $newBalance): bool
    {
        try {
            $float = WalletFloat::findOrFail($floatId);
            $float->balance = $newBalance;
            return $float->save();
        } catch (ModelNotFoundException $e) {
            logger()->warning("Float non trouvé pour updateFloatBalance, ID : $floatId");
            return false;
        }
    }

    /**
     * Récupérer les wallets avec leurs relations
     */
    public function getWithUser(string $id): ?Wallet
    {
        try {
            return Wallet::with(['user', 'floats', 'wallet_transactions'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            logger()->warning("Wallet non trouvé avec relations, ID : $id");
            return null;
        } catch (Exception $e) {
            logger()->error("Erreur lors de la récupération du wallet avec relations $id : " . $e->getMessage());
            return null;
        }
    }

    /**
     * Vérifier si un wallet existe pour un utilisateur
     */
    public function walletExistsForUser(string $userId): bool
    {
        try {
            return Wallet::where('user_id', $userId)->exists();
        } catch (Exception $e) {
            logger()->error("Erreur lors de la vérification de l'existence du wallet pour l'utilisateur $userId : " . $e->getMessage());
            return false;
        }
    }



    public function getUserStats($userId)
    {
        try {
            $nbClientsAssignes = User::where('assigned_user', $userId)
                ->whereNull('deleted_at')
                ->where(function ($query) {
                    $query->where('is_pro', true)
                        ->orWhereHas('role', function ($q) {
                            $q->where('slug', RoleEnum::PRO);
                        });
                })
                ->count();

            $wallet = Wallet::where('user_id', $userId)->first();

            if (!$wallet) {
                return [
                    'solde' => 0,
                    'nbPaiements' => 0,
                    'totalPaye' => 0,
                    'lastTx' => null,
                    'nbClientsAssignes' => $nbClientsAssignes,
                    'nbTransactions' => 0,
                    'commission' => 0,
                ];
            }

            // 🔹 Récupération de toutes les transactions liées au wallet
            $transactions = WalletTransaction::where('wallet_id', $wallet->id)
                ->orderBy('created_at', 'desc')
                ->get();

            // 🔹 Calcul des statistiques
            $nbTransactions = $transactions->count(); // total transactions
            $nbPaiements = $transactions->where('type', 'topup')->count(); // paiements uniquement
            $totalPaye = $transactions->where('type', 'topup')->sum('amount');
            $lastTx = $transactions->first();


            // 🔹 Format de la dernière transaction
            $lastTxInfo = $lastTx
                ? $lastTx->amount . ' GNF le ' . $lastTx->created_at->format('d/m/Y')
                : null;

            // 🔹 Total des commissions (si le champ 'type' ou 'category' == 'commission')
            // $commission = $transactions
            //     ->where('type', 'commission')
            //     ->sum('amount');

            // 🔹 Retour des statistiques
            return [
                'solde' => (int) $wallet->cash_available,
                'nbPaiements' => $nbPaiements,
                'totalPaye' => (int) $totalPaye,
                'lastTx' => $lastTxInfo,
                'nbClientsAssignes' => $nbClientsAssignes,
                'nbTransactions' => $nbTransactions,
                'commission' => (int) $wallet->commission_available,
            ];
        } catch (Exception $e) {
            logger()->error("Erreur dans getUserStats pour user {$userId} : " . $e->getMessage());

            return [
                'solde' => 0,
                'nbPaiements' => 0,
                'totalPaye' => 0,
                'lastTx' => null,
                'nbClientsAssignes' => 0,
                'nbTransactions' => 0,
                'commission' => 0,
            ];
        }
    }


    /**
     * Bloque un montant dans le wallet
     */
    public function blockAmount(string $walletId, int $amount): bool
    {
        $wallet = Wallet::where('id', $walletId)->lockForUpdate()->first();
        if (!$wallet) {
            throw new ModelNotFoundException("Wallet not found");
        }

        $availableBalance = $wallet->cash_available - $wallet->blocked_amount;
        if ($availableBalance < $amount) {
            throw new \Exception("Insufficient available balance to block");
        }

        $wallet->blocked_amount += $amount;
        return $wallet->save();
    }

    /**
     * Débloque un montant dans le wallet
     */
    public function unblockAmount(string $walletId, int $amount): bool
    {
        $wallet = Wallet::where('id', $walletId)->lockForUpdate()->first();
        if (!$wallet) {
            throw new ModelNotFoundException("Wallet not found");
        }

        if ($wallet->blocked_amount < $amount) {
            throw new \Exception("Cannot unblock more than blocked amount");
        }

        $wallet->blocked_amount -= $amount;
        return $wallet->save();
    }

    /**
     * Débloque et retire un montant (pour approbation de retrait)
     */
    public function unblockAndWithdraw(string $walletId, int $amount): bool
    {
        $wallet = Wallet::where('id', $walletId)->lockForUpdate()->first();
        if (!$wallet) {
            throw new ModelNotFoundException("Wallet not found");
        }

        if ($wallet->blocked_amount < $amount) {
            throw new \Exception("Blocked amount insufficient");
        }

        if ($wallet->cash_available < $amount) {
            throw new \Exception("Cash available insufficient");
        }

        $wallet->blocked_amount -= $amount;
        $wallet->cash_available -= $amount;
        return $wallet->save();
    }

    /**
     * Récupère le solde cohérent de l'utilisateur et son wallet
     * Garantit que les soldes sont les mêmes partout
     */
    public function getConsistentBalance(string $userId): array
    {
        DB::beginTransaction();

        try {
            // 🔒 Verrouiller l'utilisateur et son wallet pour éviter les conflits
            $user = User::with(['wallet'])->lockForUpdate()->findOrFail($userId);

            if (!$user->wallet) {
                throw new Exception("Aucun wallet trouvé pour l'utilisateur");
            }

            $wallet = $user->wallet;

            // 🔄 SYNCHRONISATION DES SOLDES - Garantir la cohérence
            $inconsistencies = [];

            // Vérifier la cohérence entre user.solde_portefeuille et wallet.cash_available
            if ($user->solde_portefeuille != $wallet->cash_available) {
                logger()->warning("⚠️ Incohérence détectée: user.solde_portefeuille ({$user->solde_portefeuille}) != wallet.cash_available ({$wallet->cash_available})");

                // Corriger l'incohérence en prenant wallet.cash_available comme source de vérité
                $user->solde_portefeuille = $wallet->cash_available;
                $user->save();

                $inconsistencies[] = "Solde utilisateur corrigé";
            }

            DB::commit();

            // 📊 Retourner les données cohérentes
            return [
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'display_name' => $user->display_name,
                    'solde_portefeuille' => (int) $user->solde_portefeuille,
                ],
                'wallet' => [
                    'id' => $wallet->id,
                    'cash_available' => (int) $wallet->cash_available,
                    'commission_available' => (int) $wallet->commission_available,
                    'blocked_amount' => (int) $wallet->blocked_amount,
                    'net_balance' => (int) ($wallet->cash_available - $wallet->blocked_amount),
                ],
                'consistency' => [
                    'is_consistent' => empty($inconsistencies),
                    'corrections_applied' => $inconsistencies,
                    'verified_at' => now()->toISOString(),
                ],
                'summary' => [
                    'available_balance' => (int) $wallet->cash_available,
                    'formatted_balance' => number_format($wallet->cash_available, 0, ',', ' ') . ' GNF',
                ]
            ];
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            logger()->error("❌ Utilisateur ou wallet non trouvé pour getConsistentBalance", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Utilisateur ou wallet non trouvé',
                'available_balance' => 0,
                'formatted_balance' => '0 GNF'
            ];
        } catch (Exception $e) {
            DB::rollBack();
            logger()->error("💥 Erreur lors de getConsistentBalance", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Erreur lors de la récupération du solde',
                'available_balance' => 0,
                'formatted_balance' => '0 GNF'
            ];
        }
    }

    /**
     * Version simplifiée pour récupérer uniquement le solde disponible
     * Utilisée dans l'interface utilisateur
     */
    public function getAvailableBalance(string $userId): int
    {
        try {
            $balanceData = $this->getConsistentBalance($userId);

            if ($balanceData['success']) {
                return $balanceData['summary']['available_balance'];
            }

            return 0;
        } catch (Exception $e) {
            logger()->error("Erreur dans getAvailableBalance pour user {$userId}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Vérifie si le solde est suffisant pour une transaction
     */
    public function hasSufficientBalance(string $userId, int $amount): array
    {
        try {
            $balanceData = $this->getConsistentBalance($userId);

            if (!$balanceData['success']) {
                return [
                    'sufficient' => false,
                    'available_balance' => 0,
                    'required_amount' => $amount,
                    'deficit' => $amount,
                    'message' => 'Impossible de vérifier le solde'
                ];
            }

            $availableBalance = $balanceData['wallet']['net_balance'];
            $sufficient = $availableBalance >= $amount;

            return [
                'sufficient' => $sufficient,
                'available_balance' => $availableBalance,
                'required_amount' => $amount,
                'deficit' => $sufficient ? 0 : ($amount - $availableBalance),
                'message' => $sufficient
                    ? 'Solde suffisant'
                    : 'Solde insuffisant: ' . number_format($availableBalance, 0, ',', ' ') . ' GNF disponible',
                'formatted_available' => number_format($availableBalance, 0, ',', ' ') . ' GNF',
                'formatted_required' => number_format($amount, 0, ',', ' ') . ' GNF'
            ];
        } catch (Exception $e) {
            logger()->error("Erreur dans hasSufficientBalance pour user {$userId}: " . $e->getMessage());

            return [
                'sufficient' => false,
                'available_balance' => 0,
                'required_amount' => $amount,
                'deficit' => $amount,
                'message' => 'Erreur lors de la vérification du solde'
            ];
        }
    }

    /**
     * Récupère le total de tous les soldes disponibles (cash + commissions)
     */
    public function getTotalCashAvailable(): int
    {
        try {
            return (int) Wallet::sum(
                DB::raw('cash_available + commission_available')
            );
        } catch (Exception $e) {
            logger()->error(
                "Erreur lors du calcul du total cash + commissions : " . $e->getMessage()
            );
            return 0;
        }
    }


    /**
     * {@inheritdoc}
     */
    public function getCommissionSummary(?string $currency = null): array
    {
        try {
            // Rôles fixes que nous voulons inclure
            $roles = $this->defaultCommissions;

            // Récupérer tous les wallets en respectant les permissions existantes
            $wallets = $this->getAll();

            // Initialiser les totaux par rôle
            $commissionsByRole = [];
            foreach ($roles as $role) {
                $commissionsByRole[$role] = [
                    'commission_available' => 0,
                    'commission_balance' => 0,
                    'cash_available' => 0,
                    'blocked_amount' => 0,
                    'user_count' => 0
                ];
            }

            // Filtrer par devise si spécifiée
            if ($currency) {
                $wallets = array_filter($wallets, function ($wallet) use ($currency) {
                    return $wallet->currency === $currency;
                });
            }

            // Calculer les totaux par rôle
            foreach ($wallets as $wallet) {
                $user = $wallet->user;
                if (!$user || !$user->role) {
                    continue;
                }

                $roleSlug = $user->role->slug;

                // Ignorer le SUPER_ADMIN
                if ($roleSlug === RoleEnum::SUPER_ADMIN) {
                    continue;
                }

                // Vérifier si le rôle est dans ceux que nous suivons
                if (in_array($roleSlug, $roles)) {
                    $commissionsByRole[$roleSlug]['commission_available'] += $wallet->commission_available;
                    $commissionsByRole[$roleSlug]['commission_balance'] += $wallet->commission_balance;
                    $commissionsByRole[$roleSlug]['cash_available'] += $wallet->cash_available;
                    $commissionsByRole[$roleSlug]['blocked_amount'] += $wallet->blocked_amount;
                    $commissionsByRole[$roleSlug]['user_count']++;
                }
            }

            // Calculer les totaux globaux (sans SUPER_ADMIN)
            $totalCommissionAvailable = 0;
            $totalCommissionBalance = 0;
            $totalCashAvailable = 0;
            $totalBlockedAmount = 0;
            $totalUsers = 0;

            foreach ($commissionsByRole as $roleData) {
                $totalCommissionAvailable += $roleData['commission_available'];
                $totalCommissionBalance += $roleData['commission_balance'];
                $totalCashAvailable += $roleData['cash_available'];
                $totalBlockedAmount += $roleData['blocked_amount'];
                $totalUsers += $roleData['user_count'];
            }

            // Formater la réponse
            $formattedResponse = [
                'commissions_by_role' => [],
                'totals' => [
                    'commission_available' => (int) $totalCommissionAvailable,
                    'commission_balance' => (int) $totalCommissionBalance,
                    'cash_available' => (int) $totalCashAvailable,
                    'blocked_amount' => (int) $totalBlockedAmount,
                    'net_balance' => (int) ($totalCashAvailable - $totalBlockedAmount),
                    'user_count' => $totalUsers,
                    'formatted_commission_available' => $this->formatAmount($totalCommissionAvailable),
                    'formatted_commission_balance' => $this->formatAmount($totalCommissionBalance),
                    'formatted_cash_available' => $this->formatAmount($totalCashAvailable),
                    'formatted_net_balance' => $this->formatAmount($totalCashAvailable - $totalBlockedAmount)
                ],
                'metadata' => [
                    'currency' => $currency ?? 'GNF',
                    'generated_at' => now()->toISOString(),
                    'roles_included' => $roles,
                    'data_source' => 'WalletRepository::getAll()'
                ]
            ];

            // Ajouter les données formatées par rôle
            foreach ($commissionsByRole as $role => $data) {
                $userCount = $data['user_count'];
                $averageCommission = $userCount > 0 ? $data['commission_available'] / $userCount : 0;

                $formattedResponse['commissions_by_role'][$role] = [
                    'commission_available' => (int) $data['commission_available'],
                    'commission_balance' => (int) $data['commission_balance'],
                    'cash_available' => (int) $data['cash_available'],
                    'blocked_amount' => (int) $data['blocked_amount'],
                    'net_balance' => (int) ($data['cash_available'] - $data['blocked_amount']),
                    'user_count' => $userCount,
                    'average_commission' => (int) $averageCommission,
                    'formatted_commission_available' => $this->formatAmount($data['commission_available']),
                    'formatted_commission_balance' => $this->formatAmount($data['commission_balance']),
                    'formatted_cash_available' => $this->formatAmount($data['cash_available']),
                    'formatted_average_commission' => $this->formatAmount($averageCommission),
                    'percentage_of_total' => $totalCommissionAvailable > 0
                        ? round(($data['commission_available'] / $totalCommissionAvailable) * 100, 2)
                        : 0
                ];
            }

            return $formattedResponse;
        } catch (\Exception $e) {
            Log::error('Erreur dans getCommissionSummary: ' . $e->getMessage(), [
                'currency' => $currency,
                'user_id' => $this->user->id ?? null
            ]);

            // Retourner un résultat vide en cas d'erreur
            $result = [
                'commissions_by_role' => [
                    'EDG' => $this->getEmptyRoleData(),
                    'GSS' => $this->getEmptyRoleData(),
                    'SOUS-ADMIN' => $this->getEmptyRoleData()
                ],
                'totals' => [
                    'commission_available' => 0,
                    'commission_balance' => 0,
                    'cash_available' => 0,
                    'blocked_amount' => 0,
                    'net_balance' => 0,
                    'user_count' => 0,
                    'formatted_commission_available' => '0 GNF',
                    'formatted_commission_balance' => '0 GNF',
                    'formatted_cash_available' => '0 GNF'
                ],
                'error' => 'Erreur lors du calcul des commissions'
            ];

            return $result;
        }
    }

    /**
     * Retourne des données vides pour un rôle
     */
    private function getEmptyRoleData(): array
    {
        return [
            'commission_available' => 0,
            'commission_balance' => 0,
            'cash_available' => 0,
            'blocked_amount' => 0,
            'net_balance' => 0,
            'user_count' => 0,
            'average_commission' => 0,
            'formatted_commission_available' => '0 GNF',
            'formatted_commission_balance' => '0 GNF',
            'formatted_cash_available' => '0 GNF',
            'formatted_average_commission' => '0 GNF',
            'percentage_of_total' => 0
        ];
    }

    /**
     * Formate un montant pour l'affichage
     */
    private function formatAmount($amount): string
    {
        $amount = (int) $amount;
        return number_format($amount, 0, ',', ' ') . ' GNF';
    }
}
