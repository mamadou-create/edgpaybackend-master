<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Creance;
use App\Models\CreanceTransaction;
use App\Models\CreditProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestrateur central du module créances PRO.
 *
 * Gère :
 *   - Création de créance (avec débit ledger)
 *   - Soumission de paiement par le client
 *   - Validation / rejet admin (double sécurité DB::transaction + lockForUpdate)
 *   - Mise à jour automatique score après validation
 */
class CreanceService
{
    public function __construct(
        private readonly FinancialLedgerService  $ledger,
        private readonly RiskScoringService      $scoring,
        private readonly AnomalyDetectionService $anomaly,
        private readonly AuditLogService         $audit,
    ) {}

    private function fmtGnf(float $v): string
    {
        return number_format((float) $v, 0, '.', ' ') . ' GNF';
    }

    /**
     * Crée un avoir (crédit) dans le wallet du client pour un excédent de paiement.
     * Idempotent via la référence (si déjà créée, retourne le montant existant).
     */
    private function creditWalletOverpayment(
        User $client,
        float $excessAmount,
        string $reference,
        array $metadata = [],
        ?string $description = null,
    ): int {
        $amount = (int) round($excessAmount);
        if ($amount <= 0) {
            return 0;
        }

        return (int) DB::transaction(function () use ($client, $amount, $reference, $metadata, $description) {
            // Verrouiller ou créer le wallet
            $wallet = Wallet::query()->where('user_id', $client->id)->lockForUpdate()->first();
            if (!($wallet instanceof Wallet)) {
                $wallet = Wallet::create([
                    'user_id' => $client->id,
                    'currency' => 'GNF',
                    'cash_available' => 0,
                    'blocked_amount' => 0,
                    'commission_available' => 0,
                    'commission_balance' => 0,
                ]);
                $wallet = Wallet::query()->where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            // Idempotence: si la transaction existe déjà, ne pas doubler.
            $existing = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', 'credit_note')
                ->where('reference', $reference)
                ->first();
            if ($existing instanceof WalletTransaction) {
                return (int) $existing->amount;
            }

            $wallet->cash_available += $amount;
            $wallet->save();

            // Sync aussi le solde utilisateur (champ utilisé ailleurs).
            $lockedUser = User::query()->lockForUpdate()->findOrFail($client->id);
            $lockedUser->solde_portefeuille = (int) ($lockedUser->solde_portefeuille ?? 0) + $amount;
            $lockedUser->save();

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $client->id,
                'amount' => $amount,
                'type' => 'credit_note',
                'reference' => $reference,
                'description' => $description ?? 'Avoir (excédent paiement créance)',
                'metadata' => array_merge([
                    'source' => 'creance_overpayment',
                    'timestamp' => now()->toISOString(),
                ], $metadata),
            ]);

            return $amount;
        });
    }

    /**
     * Débite le wallet du client et applique immédiatement le paiement sur une créance.
     * Le paiement est marqué comme "valide" (pas de validation admin, car source = wallet).
     * Idempotent via la référence (si déjà appliqué, retourne le montant débité précédemment).
     *
     * @return array{wallet_debite:int, creance_id:string, transaction_id:string}
     */
    public function payerCreanceAvecWallet(
        User $client,
        Creance $creance,
        float $amount,
        string $reference,
        array $metadata = [],
    ): array {
        $amountInt = (int) round($amount);
        if ($amountInt <= 0) {
            throw new \RuntimeException('Montant wallet invalide.');
        }

        return DB::transaction(function () use ($client, $creance, $amountInt, $reference, $metadata) {
            $wallet = Wallet::query()->where('user_id', $client->id)->lockForUpdate()->first();
            if (!($wallet instanceof Wallet)) {
                $wallet = Wallet::create([
                    'user_id' => $client->id,
                    'currency' => 'GNF',
                    'cash_available' => 0,
                    'blocked_amount' => 0,
                    'commission_available' => 0,
                    'commission_balance' => 0,
                ]);
                $wallet = Wallet::query()->where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            $existingWalletTx = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', 'debit_wallet_creance')
                ->where('reference', $reference)
                ->first();

            if ($existingWalletTx instanceof WalletTransaction) {
                $existingTxId = (string) (($existingWalletTx->metadata['creance_transaction_id'] ?? '') ?: '');
                return [
                    'wallet_debite' => (int) abs((int) $existingWalletTx->amount),
                    'creance_id' => (string) $creance->id,
                    'transaction_id' => $existingTxId,
                ];
            }

            /** @var Creance $lockedCreance */
            $lockedCreance = Creance::query()->lockForUpdate()->findOrFail($creance->id);

            if ($lockedCreance->user_id !== $client->id) {
                throw new \RuntimeException('Accès non autorisé à cette créance.');
            }
            if (in_array($lockedCreance->statut, ['payee', 'annulee'])) {
                throw new \RuntimeException("Créance déjà {$lockedCreance->statut}.");
            }

            $restant = (int) round((float) $lockedCreance->montant_restant);
            if ($restant <= 0) {
                throw new \RuntimeException('Aucun montant restant à payer.');
            }

            $toPay = min($amountInt, $restant);
            if ($toPay <= 0) {
                throw new \RuntimeException('Montant wallet invalide.');
            }

            if ((int) $wallet->cash_available < $toPay) {
                throw new \RuntimeException('Solde portefeuille insuffisant.');
            }

            $wallet->cash_available -= $toPay;
            $wallet->save();

            $lockedUser = User::query()->lockForUpdate()->findOrFail($client->id);
            $lockedUser->solde_portefeuille = max(0, (int) ($lockedUser->solde_portefeuille ?? 0) - $toPay);
            $lockedUser->save();

            $montantAvant = (float) $lockedCreance->montant_restant;
            $montantApres = max(0.0, $montantAvant - (float) $toPay);
            $type = $montantApres <= 0.01 ? 'paiement_total' : 'paiement_partiel';

            $tx = CreanceTransaction::create([
                'creance_id' => $lockedCreance->id,
                'user_id' => $client->id,
                'validateur_id' => null,
                'montant' => (float) $toPay,
                'montant_avant' => $montantAvant,
                'montant_apres' => $montantApres,
                'type' => $type,
                'statut' => 'valide',
                'preuve_fichier' => null,
                'preuve_mimetype' => null,
                'preuve_hash' => null,
                'receipt_number' => $this->genererNumeroRecu(),
                'receipt_issued_at' => now(),
                'idempotency_key' => Str::uuid()->toString(),
                'notes' => 'Paiement via portefeuille (avoir).',
                'ip_soumission' => request()->ip(),
                'valide_at' => now(),
            ]);

            $nouveauMontantPaye = (float) $lockedCreance->montant_paye + (float) $toPay;
            $nouveauMontantRestant = max(0.0, (float) $lockedCreance->montant_total - $nouveauMontantPaye);
            $nouveauStatut = $nouveauMontantRestant <= 0.01
                ? 'payee'
                : ($nouveauMontantPaye > 0 ? 'partiellement_payee' : 'en_cours');

            $joursRetard = 0;
            if ($lockedCreance->date_echeance && $lockedCreance->date_echeance->isPast()) {
                $joursRetard = (int) $lockedCreance->date_echeance->diffInDays(now());
            }

            $lockedCreance->update([
                'montant_paye' => $nouveauMontantPaye,
                'montant_restant' => $nouveauMontantRestant,
                'statut' => $nouveauStatut,
                'jours_retard' => $joursRetard,
                'date_paiement_effectif' => $nouveauStatut === 'payee' ? now()->toDateString() : null,
            ]);

            $walletTx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $client->id,
                'amount' => -$toPay,
                'type' => 'debit_wallet_creance',
                'reference' => $reference,
                'description' => 'Paiement créance via portefeuille',
                'metadata' => array_merge([
                    'source' => 'creance_wallet_payment',
                    'timestamp' => now()->toISOString(),
                    'creance_id' => (string) $lockedCreance->id,
                    'creance_transaction_id' => (string) $tx->id,
                ], $metadata),
            ]);

            $this->ledger->crediter(
                $lockedCreance->client,
                (float) $toPay,
                CreanceTransaction::class,
                $tx->id,
                "Paiement (wallet) — créance #{$lockedCreance->reference}",
                null
            );

            $this->scoring->recalculerScore($lockedCreance->client, 'paiement_valide', (string) $tx->id);
            $this->anomaly->analyserClient($lockedCreance->client, $lockedCreance->id, Creance::class);

            AuditLogService::log(
                AuditLogService::ACTION_VALIDATION_PAIEMENT,
                $tx->id,
                CreanceTransaction::class,
                'succes',
                null,
                ['statut_apres' => 'valide', 'creance_statut' => $nouveauStatut],
                [
                    'admin_id' => null,
                    'source' => 'wallet',
                    'wallet_transaction_id' => (string) $walletTx->id,
                    'wallet_reference' => $reference,
                ]
            );

            return [
                'wallet_debite' => $toPay,
                'creance_id' => (string) $lockedCreance->id,
                'transaction_id' => (string) $tx->id,
            ];
        });
    }

    /**
     * Débite le wallet du client et applique immédiatement le paiement sur ses créances impayées
     * (répartition automatique FIFO). Idempotent via la référence.
     *
     * @return array{wallet_debite:int, creances_ciblees:int}
     */
    public function payerTotalAvecWallet(
        User $client,
        float $amount,
        string $reference,
        array $metadata = [],
    ): array {
        $amountInt = (int) round($amount);
        if ($amountInt <= 0) {
            throw new \RuntimeException('Montant wallet invalide.');
        }

        return DB::transaction(function () use ($client, $amountInt, $reference, $metadata) {
            $wallet = Wallet::query()->where('user_id', $client->id)->lockForUpdate()->first();
            if (!($wallet instanceof Wallet)) {
                $wallet = Wallet::create([
                    'user_id' => $client->id,
                    'currency' => 'GNF',
                    'cash_available' => 0,
                    'blocked_amount' => 0,
                    'commission_available' => 0,
                    'commission_balance' => 0,
                ]);
                $wallet = Wallet::query()->where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            }

            $existingWalletTx = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', 'debit_wallet_creance_total')
                ->where('reference', $reference)
                ->first();

            if ($existingWalletTx instanceof WalletTransaction) {
                $creancesCiblees = (int) (($existingWalletTx->metadata['creances_ciblees'] ?? 0) ?: 0);
                return [
                    'wallet_debite' => (int) abs((int) $existingWalletTx->amount),
                    'creances_ciblees' => $creancesCiblees,
                ];
            }

            if ((int) $wallet->cash_available < $amountInt) {
                throw new \RuntimeException('Solde portefeuille insuffisant.');
            }

            $creances = Creance::query()
                ->where('user_id', $client->id)
                ->whereNotIn('statut', ['payee', 'annulee'])
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($creances->isEmpty()) {
                throw new \RuntimeException('Aucune créance impayée trouvée.');
            }

            $reste = $amountInt;
            $creancesCiblees = 0;

            foreach ($creances as $creance) {
                if ($reste <= 0) {
                    break;
                }

                if (in_array($creance->statut, ['payee', 'annulee'])) {
                    continue;
                }

                $restant = (int) round((float) $creance->montant_restant);
                if ($restant <= 0) {
                    continue;
                }

                $toPay = min($reste, $restant);
                if ($toPay <= 0) {
                    continue;
                }

                $montantAvant = (float) $creance->montant_restant;
                $montantApres = max(0.0, $montantAvant - (float) $toPay);
                $type = $montantApres <= 0.01 ? 'paiement_total' : 'paiement_partiel';

                $tx = CreanceTransaction::create([
                    'creance_id' => $creance->id,
                    'user_id' => $client->id,
                    'validateur_id' => null,
                    'montant' => (float) $toPay,
                    'montant_avant' => $montantAvant,
                    'montant_apres' => $montantApres,
                    'type' => $type,
                    'statut' => 'valide',
                    'preuve_fichier' => null,
                    'preuve_mimetype' => null,
                    'preuve_hash' => null,
                    'receipt_number' => $this->genererNumeroRecu(),
                    'receipt_issued_at' => now(),
                    'idempotency_key' => Str::uuid()->toString(),
                    'notes' => 'Paiement via portefeuille (avoir).',
                    'ip_soumission' => request()->ip(),
                    'valide_at' => now(),
                ]);

                $nouveauMontantPaye = (float) $creance->montant_paye + (float) $toPay;
                $nouveauMontantRestant = max(0.0, (float) $creance->montant_total - $nouveauMontantPaye);
                $nouveauStatut = $nouveauMontantRestant <= 0.01
                    ? 'payee'
                    : ($nouveauMontantPaye > 0 ? 'partiellement_payee' : 'en_cours');

                $joursRetard = 0;
                if ($creance->date_echeance && $creance->date_echeance->isPast()) {
                    $joursRetard = (int) $creance->date_echeance->diffInDays(now());
                }

                $creance->update([
                    'montant_paye' => $nouveauMontantPaye,
                    'montant_restant' => $nouveauMontantRestant,
                    'statut' => $nouveauStatut,
                    'jours_retard' => $joursRetard,
                    'date_paiement_effectif' => $nouveauStatut === 'payee' ? now()->toDateString() : null,
                ]);

                $this->ledger->crediter(
                    $creance->client,
                    (float) $toPay,
                    CreanceTransaction::class,
                    $tx->id,
                    "Paiement (wallet) — créance #{$creance->reference}",
                    null
                );

                $this->scoring->recalculerScore($creance->client, 'paiement_valide', (string) $tx->id);
                $this->anomaly->analyserClient($creance->client, $creance->id, Creance::class);

                $reste -= $toPay;
                $creancesCiblees++;
            }

            $debited = $amountInt - $reste;
            if ($debited <= 0) {
                throw new \RuntimeException('Aucun paiement wallet n\'a pu être appliqué.');
            }

            $wallet->cash_available -= $debited;
            $wallet->save();

            $lockedUser = User::query()->lockForUpdate()->findOrFail($client->id);
            $lockedUser->solde_portefeuille = max(0, (int) ($lockedUser->solde_portefeuille ?? 0) - $debited);
            $lockedUser->save();

            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $client->id,
                'amount' => -$debited,
                'type' => 'debit_wallet_creance_total',
                'reference' => $reference,
                'description' => 'Paiement global créances via portefeuille',
                'metadata' => array_merge([
                    'source' => 'creance_wallet_payment',
                    'timestamp' => now()->toISOString(),
                    'creances_ciblees' => $creancesCiblees,
                ], $metadata),
            ]);

            return [
                'wallet_debite' => $debited,
                'creances_ciblees' => $creancesCiblees,
            ];
        });
    }

    // ─── Création d'une créance ───────────────────────────────────────────

    /**
     * Crée une nouvelle créance pour un client PRO.
     * Vérifie l'éligibilité et enregistre l'écriture de débit dans le ledger.
     *
     * @throws BusinessException si client non éligible
     */
    public function creerCreance(
        User   $client,
        float  $montant,
        string $description,
        ?string $dateEcheance,
        User   $admin,
        array  $metadata = [],
        bool   $bypassCreditLimit = false
    ): Creance {
        if (! $bypassCreditLimit) {
            // Vérifier éligibilité crédit
            $motifRefus = $this->scoring->verifierEligibilite($client, $montant);
            if ($motifRefus) {
                AuditLogService::log(
                    AuditLogService::ACTION_CREATION_CREANCE,
                    $client->id,
                    User::class,
                    'echec',
                    null,
                    null,
                    ['motif' => $motifRefus, 'montant' => $montant]
                );
                throw new BusinessException($motifRefus);
            }
        }

        return DB::transaction(function () use ($client, $montant, $description, $dateEcheance, $admin, $metadata, $bypassCreditLimit) {

            // Verrouiller le profil de crédit
            $profil = $client->creditProfile()->lockForUpdate()->firstOrFail();

            // Double vérification après lock
            if (! $bypassCreditLimit && $montant > (float) $profil->credit_disponible) {
                throw new BusinessException(
                    sprintf(
                        'Limite insuffisante après verrouillage. Disponible : %s. Augmentez la limite de crédit du client.',
                        $this->fmtGnf((float) $profil->credit_disponible)
                    )
                );
            }

            $reference = $this->genererReference();

            $creance = Creance::create([
                'user_id'          => $client->id,
                'adminstrateur_id' => $admin->id,
                'reference'        => $reference,
                'montant_total'    => $montant,
                'montant_paye'     => 0,
                'montant_restant'  => $montant,
                'statut'           => 'en_attente',
                'date_echeance'    => $dateEcheance,
                'description'      => $description,
                'idempotency_key'  => Str::uuid()->toString(),
                'metadata'         => $metadata,
            ]);

            // Écriture débit dans le ledger
            $this->ledger->debiter(
                $client,
                $montant,
                Creance::class,
                $creance->id,
                "Créance #{$reference} — {$description}",
                $admin->id
            );

            AuditLogService::log(
                AuditLogService::ACTION_CREATION_CREANCE,
                $creance->id, Creance::class,
                'succes',
                null,
                $creance->toArray(),
                ['admin_id' => $admin->id, 'bypass_credit_limit' => $bypassCreditLimit]
            );

            return $creance;
        });
    }

    // ─── Soumission d'un paiement par le client ───────────────────────────

    /**
     * Le client soumet un paiement avec ou sans preuve.
     * Cette étape ne valide pas — elle met la transaction en attente admin.
     */
    public function soumettreRembours(
        User          $client,
        Creance       $creance,
        float         $montant,
        string        $type,    // paiement_total | paiement_partiel
        ?UploadedFile $preuve = null,
        string        $notes  = ''
    ): CreanceTransaction {
        // Vérifier que la créance appartient au client
        if ($creance->user_id !== $client->id) {
            throw new \RuntimeException('Accès non autorisé à cette créance.');
        }

        // Vérifier statut
        if (in_array($creance->statut, ['payee', 'annulee'])) {
            throw new \RuntimeException("Créance déjà {$creance->statut}.");
        }

        // Vérifier montant
        if ($montant <= 0 || $montant > (float) $creance->montant_restant) {
            throw new \RuntimeException(
                sprintf('Montant invalide. Restant dû : %s', $this->fmtGnf((float) $creance->montant_restant))
            );
        }

        // Traitement fichier preuve
        $preuveInfos = $this->traiterPreuve($preuve);

        return DB::transaction(function () use ($client, $creance, $montant, $type, $preuveInfos, $notes) {

            $tx = CreanceTransaction::create([
                'creance_id'      => $creance->id,
                'user_id'         => $client->id,
                'montant'         => $montant,
                'montant_avant'   => $creance->montant_restant,
                'montant_apres'   => (float) $creance->montant_restant - $montant,
                'type'            => $type,
                'statut'          => 'en_attente',
                'preuve_fichier'  => $preuveInfos['chemin'] ?? null,
                'preuve_mimetype' => $preuveInfos['mimetype'] ?? null,
                'preuve_hash'     => $preuveInfos['hash'] ?? null,
                'notes'           => $notes,
                'ip_soumission'   => request()->ip(),
                'idempotency_key' => Str::uuid()->toString(),
            ]);

            // Analyser les anomalies en arrière-plan
            $this->anomaly->analyserClient($client, $creance->id, Creance::class);

            return $tx;
        });
    }

    /**
     * Soumet un paiement global (total restant ou partiel) et le répartit automatiquement
     * sur toutes les créances impayées du client.
     *
     * @return array{total_restant: float, montant_soumis: float, creances_ciblees: int, transactions: array}
     */
    public function soumettreRemboursTotal(
        User $client,
        ?float $montantSoumis = null,
        ?UploadedFile $preuve = null,
        string $notes = '',
        ?string $batchKey = null
    ): array {
        $creances = Creance::query()
            ->where('user_id', $client->id)
            ->whereNotIn('statut', ['payee', 'annulee'])
            ->orderBy('created_at')
            ->get();

        if ($creances->isEmpty()) {
            throw new \RuntimeException('Aucune créance impayée trouvée.');
        }

        $totalRestant = (float) $creances->sum('montant_restant');
        if ($totalRestant <= 0) {
            throw new \RuntimeException('Aucun montant restant à payer.');
        }

        $montant = $montantSoumis ?? $totalRestant;
        if ($montant <= 0) {
            throw new \RuntimeException('Montant invalide.');
        }
        if ($montant > $totalRestant) {
            throw new \RuntimeException(
                sprintf(
                    'Montant invalide. Total restant dû : %s',
                    $this->fmtGnf($totalRestant)
                )
            );
        }

        $batchKey = $batchKey ?: Str::uuid()->toString();
        $preuveInfos = $this->traiterPreuve($preuve);

        $transactions = DB::transaction(function () use ($client, $creances, $montant, $preuveInfos, $notes, $batchKey) {
            $reste = $montant;
            $created = [];

            foreach ($creances as $creance) {
                if ($reste <= 0) {
                    break;
                }

                // Vérifier statut à la volée
                if (in_array($creance->statut, ['payee', 'annulee'])) {
                    continue;
                }

                $restantCreance = (float) $creance->montant_restant;
                if ($restantCreance <= 0) {
                    continue;
                }

                $aPayer = min($reste, $restantCreance);
                if ($aPayer <= 0) {
                    continue;
                }

                $type = (abs($restantCreance - $aPayer) < 0.01)
                    ? 'paiement_total'
                    : 'paiement_partiel';

                $tx = CreanceTransaction::create([
                    'creance_id'      => $creance->id,
                    'user_id'         => $client->id,
                    'montant'         => $aPayer,
                    'montant_avant'   => $restantCreance,
                    'montant_apres'   => $restantCreance - $aPayer,
                    'type'            => $type,
                    'statut'          => 'en_attente',
                    'preuve_fichier'  => $preuveInfos['chemin'] ?? null,
                    'preuve_mimetype' => $preuveInfos['mimetype'] ?? null,
                    'preuve_hash'     => $preuveInfos['hash'] ?? null,
                    'notes'           => $notes,
                    'ip_soumission'   => request()->ip(),
                    // idempotency_key reste UNIQUE par transaction.
                    'idempotency_key' => Str::uuid()->toString(),
                    // batch_key regroupe toutes les transactions créées par une même soumission.
                    'batch_key'       => $batchKey,
                ]);

                $this->anomaly->analyserClient($client, $creance->id, Creance::class);
                $created[] = $tx;
                $reste -= $aPayer;
            }

            if (empty($created)) {
                throw new \RuntimeException('Aucune transaction de paiement n\'a pu être créée.');
            }

            return $created;
        });

        return [
            'total_restant'    => $totalRestant,
            'montant_soumis'   => $montant,
            'creances_ciblees' => count($transactions),
            'transactions'     => array_map(fn($t) => $t->toArray(), $transactions),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  VALIDATION ADMIN — DOUBLE SÉCURITÉ
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Valide un paiement soumis par un client.
     *
     * Sécurités :
     *  1. DB::transaction atomique
     *  2. lockForUpdate sur la créance (prévention concurrence)
     *  3. Vérification statut en_attente
     *  4. Vérification cohérence montant
     *  5. Écriture ledger immuable
     *  6. Recalcul score automatique post-validation
     *  7. Audit trail
     *
     * @throws \RuntimeException
     */
    public function validerPaiement(
        CreanceTransaction $transaction,
        User               $admin
    ): Creance {
        return DB::transaction(function () use ($transaction, $admin) {

            // ── 1. Verrouiller la créance ──────────────────────────────────
            $creance = Creance::lockForUpdate()->findOrFail($transaction->creance_id);

            // ── 2. Vérifier statut transaction ─────────────────────────────
            $transactionFraiche = CreanceTransaction::lockForUpdate()
                ->findOrFail($transaction->id);

            if ($transactionFraiche->statut !== 'en_attente') {
                throw new \RuntimeException(
                    "Transaction déjà traitée (statut: {$transactionFraiche->statut})."
                );
            }

            // ── 3. Vérifier statut créance ─────────────────────────────────
            if (in_array($creance->statut, ['payee', 'annulee'])) {
                throw new \RuntimeException(
                    "Créance déjà {$creance->statut} — impossible de valider."
                );
            }

            // ── 4. Normaliser montant (surpaiement => avoir) ──────────────
            $restant = (float) $creance->montant_restant;
            $montantSoumis = (float) $transactionFraiche->montant;

            if ($montantSoumis <= 0) {
                throw new \RuntimeException('Montant invalide.');
            }
            if ($restant <= 0.01) {
                throw new \RuntimeException('Aucun montant restant à payer.');
            }

            $montantValide = min($montantSoumis, $restant);
            $excess = max(0.0, $montantSoumis - $montantValide);

            if ($excess > 0.01) {
                // Idempotent: si déjà crédité (par le client au moment de soumission), ne double pas.
                $this->creditWalletOverpayment(
                    $creance->client,
                    $excess,
                    'credit_note_overpay_creance_tx:' . (string) $transactionFraiche->id,
                    [
                        'source' => 'admin_validation',
                        'creance_id' => (string) $creance->id,
                        'transaction_id' => (string) $transactionFraiche->id,
                    ],
                    'Avoir (excédent paiement créance — validation admin)'
                );
            }

            // ── 5. Mettre à jour la transaction ────────────────────────────
            $txUpdate = [
                'statut'       => 'valide',
                'validateur_id'=> $admin->id,
                'valide_at'    => now(),
                'montant_avant'=> $restant,
                'montant_apres'=> max(0, $restant - $montantValide),
            ];

            if ($excess > 0.01) {
                $existingNotes = trim((string) ($transactionFraiche->notes ?? ''));
                $append = sprintf(
                    'Validation admin: montant soumis %s, montant validé %s, excédent crédité %s.',
                    $this->fmtGnf($montantSoumis),
                    $this->fmtGnf($montantValide),
                    $this->fmtGnf($excess)
                );
                $txUpdate['notes'] = $existingNotes !== '' ? ($existingNotes . "\n" . $append) : $append;
            }

            if (empty($transactionFraiche->receipt_number)) {
                $txUpdate['receipt_number'] = $this->genererNumeroRecu();
                $txUpdate['receipt_issued_at'] = now();
            }

            $transactionFraiche->update($txUpdate);

            // ── 6. Mettre à jour la créance ────────────────────────────────
            $nouveauMontantPaye    = (float) $creance->montant_paye + (float) $montantValide;
            $nouveauMontantRestant = (float) $creance->montant_total - $nouveauMontantPaye;
            $nouveauMontantRestant = max(0, $nouveauMontantRestant);

            $nouveauStatut = $nouveauMontantRestant <= 0.01
                ? 'payee'
                : ($nouveauMontantPaye > 0 ? 'partiellement_payee' : 'en_cours');

            $joursRetard = 0;
            if ($creance->date_echeance && $creance->date_echeance->isPast()) {
                $joursRetard = (int) $creance->date_echeance->diffInDays(now());
            }

            $creance->update([
                'montant_paye'            => $nouveauMontantPaye,
                'montant_restant'         => $nouveauMontantRestant,
                'statut'                  => $nouveauStatut,
                'jours_retard'            => $joursRetard,
                'date_paiement_effectif'  => $nouveauStatut === 'payee' ? now()->toDateString() : null,
            ]);

            // ── 7. Écriture ledger immuable ────────────────────────────────
            $this->ledger->crediter(
                $creance->client,
                (float) $montantValide,
                CreanceTransaction::class,
                $transactionFraiche->id,
                "Paiement validé — créance #{$creance->reference}",
                $admin->id
            );

            // ── 8. Recalcul score client ───────────────────────────────────
            $this->scoring->recalculerScore(
                $creance->client,
                'paiement_valide',
                $transactionFraiche->id
            );

            // ── 9. Détection anomalie délai excessif ───────────────────────
            $this->anomaly->analyserClient(
                $creance->client,
                $creance->id,
                Creance::class
            );

            // ── 10. Audit trail ────────────────────────────────────────────
            AuditLogService::log(
                AuditLogService::ACTION_VALIDATION_PAIEMENT,
                $transactionFraiche->id,
                CreanceTransaction::class,
                'succes',
                ['statut_avant' => 'en_attente'],
                ['statut_apres' => 'valide', 'creance_statut' => $nouveauStatut],
                [
                    'admin_id' => $admin->id,
                    'montant' => $montantValide,
                    'montant_soumis' => $montantSoumis,
                    'avoir_montant' => $excess,
                ]
            );

            return $creance->fresh(['transactions', 'client.creditProfile']);
        });
    }

    private function genererNumeroRecu(): string
    {
        $date = now()->format('Ymd');

        for ($i = 0; $i < 10; $i++) {
            $suffix = strtoupper(Str::random(6));
            $candidate = "MDING-{$date}-{$suffix}";

            $exists = CreanceTransaction::where('receipt_number', $candidate)->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return 'MDING-' . $date . '-' . strtoupper(Str::uuid()->toString());
    }

    // ─── Rejet d'un paiement ─────────────────────────────────────────────

    public function rejeterPaiement(
        CreanceTransaction $transaction,
        User               $admin,
        string             $motif
    ): CreanceTransaction {
        return DB::transaction(function () use ($transaction, $admin, $motif) {

            $tx = CreanceTransaction::lockForUpdate()->findOrFail($transaction->id);

            if ($tx->statut !== 'en_attente') {
                throw new \RuntimeException("Transaction déjà traitée (statut: {$tx->statut}).");
            }

            $tx->update([
                'statut'       => 'rejete',
                'validateur_id'=> $admin->id,
                'motif_rejet'  => $motif,
                'valide_at'    => now(),
            ]);

            // Détecter preuve invalide répétée
            $this->anomaly->detecterPreuveInvalideRepetee($tx->client);

            AuditLogService::log(
                AuditLogService::ACTION_REJET_PAIEMENT,
                $tx->id, CreanceTransaction::class,
                'succes',
                ['statut_avant' => 'en_attente'],
                ['statut_apres' => 'rejete'],
                ['motif' => $motif, 'admin_id' => $admin->id]
            );

            return $tx->fresh();
        });
    }

    // ─── Gestion limite de crédit ─────────────────────────────────────────

    public function modifierLimiteCredit(
        User  $client,
        float $nouvelleLimite,
        User  $admin,
        string $motif = ''
    ): CreditProfile {
        $profil = CreditProfile::where('user_id', $client->id)->lockForUpdate()->first();

        if (! $profil) {
            $profil = CreditProfile::create([
                'user_id'            => $client->id,
                'credit_limite'      => 0,
                'credit_disponible'  => 0,
                'score_fiabilite'    => 50,
                'niveau_risque'      => 'moyen',
                'est_bloque'         => false,
                'total_encours'      => 0,
                'anciennete_mois'    => (int) $client->created_at?->diffInMonths(now()),
                'score_calcule_at'   => null,
            ]);
        }

        $ancienneLimite = $profil->credit_limite;

        $profil->update([
            'credit_limite'     => $nouvelleLimite,
            'credit_disponible' => max(0, $nouvelleLimite - (float) $profil->total_encours),
        ]);

        AuditLogService::log(
            AuditLogService::ACTION_MODIFICATION_LIMITE,
            $client->id, User::class,
            'succes',
            ['credit_limite' => $ancienneLimite],
            ['credit_limite' => $nouvelleLimite],
            ['admin_id' => $admin->id, 'motif' => $motif]
        );

        // Le listing admin "Lister comptes créance" consomme GET /risk/clients,
        // qui est volontairement mis en cache ~25s. Après une modification de
        // limite, on invalide ce cache pour refléter immédiatement la nouvelle
        // valeur côté UI.
        try {
            foreach ([50, 200] as $perPage) {
                foreach ([1] as $page) {
                    Cache::forget(sprintf('risk.dashboard.clients.%d.%d', $perPage, $page));
                    Cache::forget(sprintf('risk.dashboard.clients.actor.%s.%d.%d', $admin->id, $perPage, $page));
                }
            }
        } catch (\Throwable) {
            // Best-effort: ne pas bloquer la mise à jour de la limite.
        }

        return $profil->fresh();
    }

    // ─── Blocage / Déblocage manuel ───────────────────────────────────────

    public function bloquerCompte(User $client, User $admin, string $motif): void
    {
        $profil = $client->creditProfile()->firstOrCreate(['user_id' => $client->id]);
        $profil->update([
            'est_bloque'    => true,
            'motif_blocage' => $motif,
        ]);

        AuditLogService::log(
            AuditLogService::ACTION_BLOCAGE_COMPTE,
            $client->id, User::class,
            'succes',
            null,
            ['est_bloque' => true],
            ['admin_id' => $admin->id, 'motif' => $motif]
        );

        // Invalidation best-effort des caches dashboard (TTL ~25s) pour que
        // les listes admin se mettent à jour immédiatement.
        try {
            Cache::forget('risk.dashboard.overview');
            Cache::forget('risk.dashboard.overview.actor.' . $admin->id);
            Cache::forget('risk.dashboard.top_clients');
            Cache::forget('risk.dashboard.top_clients.actor.' . $admin->id);

            foreach ([50, 200] as $perPage) {
                Cache::forget(sprintf('risk.dashboard.clients.%d.%d', $perPage, 1));
                Cache::forget(sprintf('risk.dashboard.clients.actor.%s.%d.%d', $admin->id, $perPage, 1));
            }
            foreach ([20, 50] as $perPage) {
                Cache::forget(sprintf('risk.dashboard.clients_risque.%d.%d', $perPage, 1));
                Cache::forget(sprintf('risk.dashboard.clients_risque.actor.%s.%d.%d', $admin->id, $perPage, 1));
            }
        } catch (\Throwable) {
            // Best-effort
        }
    }

    public function debloquerCompte(User $client, User $admin, string $note = ''): void
    {
        $profil = $client->creditProfile()->firstOrFail();
        $profil->update([
            'est_bloque'       => false,
            'bloque_jusqu_au'  => null,
            'motif_blocage'    => null,
        ]);

        AuditLogService::log(
            AuditLogService::ACTION_DEBLOCAGE_COMPTE,
            $client->id, User::class,
            'succes',
            ['est_bloque' => true],
            ['est_bloque' => false],
            ['admin_id' => $admin->id, 'note' => $note]
        );

        // Invalidation best-effort des caches dashboard (TTL ~25s).
        try {
            Cache::forget('risk.dashboard.overview');
            Cache::forget('risk.dashboard.overview.actor.' . $admin->id);
            Cache::forget('risk.dashboard.top_clients');
            Cache::forget('risk.dashboard.top_clients.actor.' . $admin->id);

            foreach ([50, 200] as $perPage) {
                Cache::forget(sprintf('risk.dashboard.clients.%d.%d', $perPage, 1));
                Cache::forget(sprintf('risk.dashboard.clients.actor.%s.%d.%d', $admin->id, $perPage, 1));
            }
            foreach ([20, 50] as $perPage) {
                Cache::forget(sprintf('risk.dashboard.clients_risque.%d.%d', $perPage, 1));
                Cache::forget(sprintf('risk.dashboard.clients_risque.actor.%s.%d.%d', $admin->id, $perPage, 1));
            }
        } catch (\Throwable) {
            // Best-effort
        }
    }

    // ─── Utilitaires ─────────────────────────────────────────────────────

    private function genererReference(): string
    {
        $prefix = 'CRE';
        $date   = now()->format('Ymd');
        $unique = strtoupper(Str::random(6));
        return "{$prefix}-{$date}-{$unique}";
    }

    /**
     * Traite le fichier preuve : vérifie le mimetype réel, hash, stocke.
     *
     * @throws \RuntimeException si type fichier non autorisé
     */
    private function traiterPreuve(?UploadedFile $fichier): array
    {
        if (! $fichier) {
            return [];
        }

        // Vérification mimetype réel (pas l'extension déclarée)
        $mimeReel = $fichier->getMimeType();
        $typesAutorises = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

        if (! in_array($mimeReel, $typesAutorises)) {
            AuditLogService::tentativeInvalide(AuditLogService::ACTION_TENTATIVE_INVALIDE, [
                'raison'    => 'type_fichier_non_autorise',
                'mime_reel' => $mimeReel,
            ]);
            throw new \RuntimeException(
                "Type de fichier non autorisé ({$mimeReel}). Acceptés : JPEG, PNG, WEBP, PDF."
            );
        }

        // Hash intégrité du fichier
        $hash  = hash_file('sha256', $fichier->getRealPath());
        $chemin = $fichier->store('preuves-paiement', 'private');

        return [
            'chemin'  => $chemin,
            'mimetype'=> $mimeReel,
            'hash'    => $hash,
        ];
    }
}
