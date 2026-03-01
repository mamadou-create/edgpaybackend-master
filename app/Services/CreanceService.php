<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Creance;
use App\Models\CreanceTransaction;
use App\Models\CreditProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

            // ── 4. Vérifier cohérence montant ──────────────────────────────
            if ($transactionFraiche->montant > (float) $creance->montant_restant + 0.01) {
                throw new \RuntimeException(
                    sprintf(
                        'Incohérence montant : transaction %s > restant %s',
                        $this->fmtGnf((float) $transactionFraiche->montant),
                        $this->fmtGnf((float) $creance->montant_restant)
                    )
                );
            }

            // ── 5. Mettre à jour la transaction ────────────────────────────
            $txUpdate = [
                'statut'       => 'valide',
                'validateur_id'=> $admin->id,
                'valide_at'    => now(),
            ];

            if (empty($transactionFraiche->receipt_number)) {
                $txUpdate['receipt_number'] = $this->genererNumeroRecu();
                $txUpdate['receipt_issued_at'] = now();
            }

            $transactionFraiche->update($txUpdate);

            // ── 6. Mettre à jour la créance ────────────────────────────────
            $nouveauMontantPaye    = (float) $creance->montant_paye + (float) $transactionFraiche->montant;
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
                (float) $transactionFraiche->montant,
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
                ['admin_id' => $admin->id, 'montant' => $transactionFraiche->montant]
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
