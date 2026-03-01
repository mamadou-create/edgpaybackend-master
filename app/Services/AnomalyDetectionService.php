<?php

namespace App\Services;

use App\Models\AnomalyFlag;
use App\Models\CreditProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moteur de détection d'anomalies et de prévention de fraude.
 *
 * Chaque règle est indépendante et peut être activée/désactivée.
 * Les anomalies critiques déclenchent un blocage automatique au seuil de 3.
 */
class AnomalyDetectionService
{
    private const SEUIL_BLOCAGE_CRITIQUES = 3;
    private const FENETRE_PARTIEL_JOURS   = 30;  // nb jours pour détecter fréquence partielle
    private const SEUIL_PARTIEL_FREQ      = 4;   // nb min paiements partiels pour anomalie
    private const MULTIPLICATEUR_MONTANT  = 3.0; // commande 3x la moyenne = anomalie
    private const SEUIL_RETARD_EXCESSIF   = 60;  // jours
    private const SEUIL_MONTANT_FAIBLE_RATIO = 0.05; // < 5% du montant total = faible

    public function __construct(
        private readonly AuditLogService $auditService,
    ) {}

    // ─── Analyse complète ────────────────────────────────────────────────

    /**
     * Lance toutes les règles de détection sur un client.
     * Retourne le nombre d'anomalies nouvellement créées.
     */
    public function analyserClient(User $user, ?string $refId = null, ?string $refType = null): int
    {
        $nouvelles = 0;
        $nouvelles += $this->detecterPaiementsPartielsFrequents($user);
        $nouvelles += $this->detecterMontantsFaiblesRepetitifs($user);
        $nouvelles += $this->detecterMontantAnormalementEleve($user, $refId, $refType);
        $nouvelles += $this->detecterPaiementApresTropLongDelai($user, $refId, $refType);
        $this->evaluerBlocageAutomatique($user);

        return $nouvelles;
    }

    // ─── Règle 1 : Paiements partiels fréquents ──────────────────────────

    private function detecterPaiementsPartielsFrequents(User $user): int
    {
        $nbPartiels = $user->creanceTransactions()
            ->where('type', 'paiement_partiel')
            ->where('statut', 'valide')
            ->where('created_at', '>=', now()->subDays(self::FENETRE_PARTIEL_JOURS))
            ->count();

        if ($nbPartiels >= self::SEUIL_PARTIEL_FREQ) {
            // Éviter doublon sur même fenêtre
            $existe = AnomalyFlag::where('user_id', $user->id)
                ->where('type_anomalie', 'paiement_partiel_frequent')
                ->where('resolved', false)
                ->where('created_at', '>=', now()->subDays(self::FENETRE_PARTIEL_JOURS))
                ->exists();

            if (! $existe) {
                $this->creerAnomalie($user, 'paiement_partiel_frequent', 'warning', null, null, [
                    'nb_partiels'   => $nbPartiels,
                    'fenetre_jours' => self::FENETRE_PARTIEL_JOURS,
                ], "Client avec {$nbPartiels} paiements partiels en " . self::FENETRE_PARTIEL_JOURS . " jours.");
                return 1;
            }
        }
        return 0;
    }

    // ─── Règle 2 : Montants proposés faibles répétitifs ──────────────────

    private function detecterMontantsFaiblesRepetitifs(User $user): int
    {
        $transactions = $user->creanceTransactions()
            ->where('statut', 'valide')
            ->where('type', 'paiement_partiel')
            ->with('creance')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $nbFaibles = 0;
        foreach ($transactions as $tx) {
            if ($tx->creance && $tx->creance->montant_total > 0) {
                $ratio = $tx->montant / $tx->creance->montant_total;
                if ($ratio < self::SEUIL_MONTANT_FAIBLE_RATIO) {
                    $nbFaibles++;
                }
            }
        }

        if ($nbFaibles >= 3) {
            $existe = AnomalyFlag::where('user_id', $user->id)
                ->where('type_anomalie', 'montant_propose_faible_repetitif')
                ->where('resolved', false)
                ->where('created_at', '>=', now()->subDays(60))
                ->exists();

            if (! $existe) {
                $this->creerAnomalie($user, 'montant_propose_faible_repetitif', 'warning', null, null, [
                    'nb_faibles' => $nbFaibles,
                ], "Montants de paiement inférieurs à 5% du total répétés {$nbFaibles} fois.");
                return 1;
            }
        }
        return 0;
    }

    // ─── Règle 3 : Montant commande anormalement élevé ───────────────────

    private function detecterMontantAnormalementEleve(
        User $user,
        ?string $refId,
        ?string $refType
    ): int {
        if (! $refId) {
            return 0;
        }

        $moyenne = $user->creances()
            ->whereNotIn('statut', ['annulee'])
            ->avg('montant_total') ?? 0;

        if ($moyenne <= 0) {
            return 0;
        }

        $creanceActuelle = $user->creances()->find($refId);
        if (! $creanceActuelle) {
            return 0;
        }

        if ($creanceActuelle->montant_total >= ($moyenne * self::MULTIPLICATEUR_MONTANT)) {
            $this->creerAnomalie(
                $user, 'montant_anormalement_eleve', 'critique',
                $refId, $refType,
                [
                    'montant_actuel' => $creanceActuelle->montant_total,
                    'moyenne_client' => round($moyenne, 2),
                    'multiplicateur' => self::MULTIPLICATEUR_MONTANT,
                ],
                sprintf('Montant %.2f est %.1fx la moyenne habituelle (%.2f).',
                    $creanceActuelle->montant_total,
                    $creanceActuelle->montant_total / $moyenne,
                    $moyenne
                )
            );
            return 1;
        }
        return 0;
    }

    // ─── Règle 4 : Paiement après délai excessif ─────────────────────────

    private function detecterPaiementApresTropLongDelai(
        User $user,
        ?string $refId,
        ?string $refType
    ): int {
        if (! $refId) {
            return 0;
        }

        $creance = $user->creances()->find($refId);
        if (! $creance || ! $creance->date_echeance) {
            return 0;
        }

        if ($creance->jours_retard > self::SEUIL_RETARD_EXCESSIF) {
            $this->creerAnomalie(
                $user, 'paiement_apres_delai_excessif', 'critique',
                $refId, $refType,
                ['jours_retard' => $creance->jours_retard],
                "Créance {$creance->reference} payée avec {$creance->jours_retard} jours de retard (seuil: " . self::SEUIL_RETARD_EXCESSIF . ")."
            );
            return 1;
        }
        return 0;
    }

    // ─── Règle 5 : Preuve invalide répétée ──────────────────────────────

    public function detecterPreuveInvalideRepetee(User $user): int
    {
        $nbRejets = $user->creanceTransactions()
            ->where('statut', 'rejete')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        if ($nbRejets >= 3) {
            $existe = AnomalyFlag::where('user_id', $user->id)
                ->where('type_anomalie', 'preuve_invalide_repetee')
                ->where('resolved', false)
                ->where('created_at', '>=', now()->subDays(30))
                ->exists();

            if (! $existe) {
                $this->creerAnomalie($user, 'preuve_invalide_repetee', 'critique', null, null, [
                    'nb_rejets' => $nbRejets,
                ], "Preuve de paiement rejetée {$nbRejets} fois en 30 jours.");
                return 1;
            }
        }
        return 0;
    }

    // ─── Évaluation blocage automatique ──────────────────────────────────

    /**
     * Bloque automatiquement le compte si >= 3 anomalies critiques non résolues.
     */
    public function evaluerBlocageAutomatique(User $user): void
    {
        $nbCritiques = AnomalyFlag::where('user_id', $user->id)
            ->where('niveau', 'critique')
            ->where('resolved', false)
            ->count();

        if ($nbCritiques >= self::SEUIL_BLOCAGE_CRITIQUES) {
            $profil = $user->creditProfile;
            if ($profil && ! $profil->est_bloque) {
                $profil->update([
                    'est_bloque'    => true,
                    'motif_blocage' => "Blocage automatique : {$nbCritiques} anomalies critiques non résolues.",
                ]);

                Log::critical('[AnomalyDetection] Compte bloqué automatiquement', [
                    'user_id'     => $user->id,
                    'nb_critiques'=> $nbCritiques,
                ]);
            }
        }
    }

    // ─── Résolution d'anomalie ────────────────────────────────────────────

    public function resoudreAnomalie(
        AnomalyFlag $anomalie,
        User $resolveur,
        string $note = ''
    ): void {
        $anomalie->update([
            'resolved'         => true,
            'resolu_par'       => $resolveur->id,
            'resolu_at'        => now(),
            'note_resolution'  => $note,
        ]);
    }

    // ─── Créer anomalie ───────────────────────────────────────────────────

    private function creerAnomalie(
        User $user,
        string $type,
        string $niveau,
        ?string $refId,
        ?string $refType,
        array $donnees,
        string $description
    ): AnomalyFlag {
        $anomalie = AnomalyFlag::create([
            'user_id'         => $user->id,
            'reference_id'    => $refId,
            'reference_type'  => $refType,
            'type_anomalie'   => $type,
            'niveau'          => $niveau,
            'description'     => $description,
            'donnees_contexte'=> $donnees,
        ]);

        Log::warning('[AnomalyDetection] Anomalie détectée', [
            'user_id' => $user->id,
            'type'    => $type,
            'niveau'  => $niveau,
        ]);

        return $anomalie;
    }
}
