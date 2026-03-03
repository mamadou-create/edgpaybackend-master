<?php

namespace App\Services;

use App\Models\CreditProfile;
use App\Models\CreditScoreHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moteur de scoring prédictif de crédit.
 *
 * Phase 1 — Heuristique déterministe.
 * Phase 2 (future) — Intégrer un modèle ML via une API externe.
 *
 * Formule :
 *   score = 50 (base)
 *         - (nb_paiements_en_retard × 10)
 *         - (nb_creances_en_retard_actif × 15)
 *         + (nb_paiements_rapides × 5)
 *         + (anciennete_mois / 2)
 *         - malus_ratio_endettement
 *   Clamped [0, 100]
 */
class RiskScoringService
{
    // ─── Constantes scoring ────────────────────────────────────────────────

    private const SCORE_BASE               = 50;
    private const MALUS_PAR_RETARD         = 10;
    private const MALUS_PAR_CREANCE_ACTIVE = 15;
    private const BONUS_PAIEMENT_RAPIDE    = 5;
    private const BONUS_ANCIENNETE_DIVISEUR= 2;
    private const MALUS_RATIO_SEUIL_50     = 5;    // -5 si encours > 50% limite
    private const MALUS_RATIO_SEUIL_80     = 15;   // -15 si encours > 80% limite

    // ─── Seuils niveau risque ─────────────────────────────────────────────

    private const SEUIL_FAIBLE  = 75;
    private const SEUIL_MOYEN   = 50;

    // ─── Limites crédit par niveau risque ────────────────────────────────

    private const LIMITE_BASE_FAIBLE = null; // conserve la limite définie par l'admin
    private const LIMITE_REDUCTION_MOYEN  = 0.75; // ×0.75
    private const LIMITE_REDUCTION_ELEVE  = 0.40; // ×0.40

    public function __construct(
        private readonly AuditLogService $auditService,
    ) {}

    private function fmtGnf(float $v): string
    {
        return number_format((float) $v, 0, '.', ' ') . ' GNF';
    }

    // ─── Point d'entrée principal ─────────────────────────────────────────

    /**
     * Recalcule le score et le niveau de risque d'un client.
     * Persiste le résultat et enregistre l'historique.
     *
     * @param  User   $user         Client PRO cible
     * @param  string $declencheur  Evénement déclencheur (ex: "paiement_valide")
     * @param  string|null $declencheurId  UUID référence déclencheur
     * @return CreditProfile        Profil mis à jour
     */
    public function recalculerScore(
        User $user,
        string $declencheur = 'recalcul_manuel',
        ?string $declencheurId = null
    ): CreditProfile {
        return DB::transaction(function () use ($user, $declencheur, $declencheurId) {

            /** @var CreditProfile $profil */
            $profil = $user->creditProfile()->lockForUpdate()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'credit_limite'     => 0,
                    'credit_disponible' => 0,
                    'score_fiabilite'   => self::SCORE_BASE,
                    'niveau_risque'     => 'moyen',
                    'anciennete_mois'   => $this->calculerAncienneteMois($user),
                ]
            );

            $variables = $this->collecterVariables($user, $profil);
            $nouveauScore = $this->calculerScore($variables);
            $nouveauNiveau = $this->determinerNiveauRisque($nouveauScore);
            $nouvelleLimite = $this->calculerNouvelleLimit($profil, $nouveauNiveau);

            $scoreAvant = $profil->score_fiabilite;
            $limiteAvant = (float) $profil->credit_limite;

            // Mise à jour profil
            $profil->fill([
                'score_fiabilite'           => $nouveauScore,
                'niveau_risque'             => $nouveauNiveau,
                'credit_limite'             => $nouvelleLimite,
                'credit_disponible'         => max(0, $nouvelleLimite - $profil->total_encours),
                'nb_creances_total'         => $variables['nb_creances_total'],
                'nb_paiements_en_retard'    => $variables['nb_retards'],
                'nb_paiements_rapides'      => $variables['nb_rapides'],
                'delai_moyen_paiement_jours'=> $variables['delai_moyen'],
                'anciennete_mois'           => $variables['anciennete_mois'],
                'montant_moyen_transaction' => $variables['montant_moyen'],
                'volume_mensuel_moyen'      => $variables['volume_mensuel'],
                'score_calcule_at'          => now(),
            ]);

            // Blocage automatique si risque élevé et ratio encours > 50%
            $this->appliquerReglesBlocage($profil);

            $profil->save();

            // Les widgets admin (ex: GET /risk/clients) sont mis en cache.
            // Quand la limite évolue automatiquement via scoring, on invalide
            // le cache global (best-effort) pour réduire les incohérences.
            if ($limiteAvant !== (float) $profil->credit_limite) {
                try {
                    foreach ([50, 200] as $perPage) {
                        Cache::forget(sprintf('risk.dashboard.clients.%d.%d', $perPage, 1));
                    }
                    foreach ([20, 50] as $perPage) {
                        Cache::forget(sprintf('risk.dashboard.clients_risque.%d.%d', $perPage, 1));
                    }
                    Cache::forget('risk.dashboard.top_clients');
                } catch (\Throwable) {
                    // Best-effort
                }
            }

            // Historique
            CreditScoreHistory::create([
                'user_id'            => $user->id,
                'score_avant'        => $scoreAvant,
                'score_apres'        => $nouveauScore,
                'niveau_risque_apres'=> $nouveauNiveau,
                'credit_limite_apres'=> $nouvelleLimite,
                'declencheur'        => $declencheur,
                'declencheur_id'     => $declencheurId,
                'variables_scoring'  => $variables,
            ]);

            Log::info('[RiskScoring] Score recalculé', [
                'user_id'  => $user->id,
                'avant'    => $scoreAvant,
                'apres'    => $nouveauScore,
                'niveau'   => $nouveauNiveau,
                'trigger'  => $declencheur,
            ]);

            return $profil->fresh();
        });
    }

    // ─── Collecte des variables ───────────────────────────────────────────

    /**
     * Collecte et calcule toutes les variables nécessaires au scoring.
     */
    private function collecterVariables(User $user, CreditProfile $profil): array
    {
        // Créances actives
        $creances = $user->creances()
            ->whereNotIn('statut', ['annulee'])
            ->get();

        $nbTotal      = $creances->count();
        $nbEnRetard   = $creances->where('statut', 'en_retard')->count();
        $nbPayees     = $creances->where('statut', 'payee')->count();
        $totalEncours = $creances->whereIn('statut', ['en_attente','en_cours','partiellement_payee','en_retard'])
                                 ->sum('montant_restant');

        // Transactions validées
        $transactions = $user->creanceTransactions()
            ->where('statut', 'valide')
            ->where('type', 'like', 'paiement%')
            ->get();

        $nbRapides = 0;
        $delais    = [];

        foreach ($transactions as $tx) {
            $creance = $tx->creance;
            if ($creance && $creance->date_echeance) {
                $joursAvant = $creance->date_echeance->diffInDays(
                    Carbon::parse($tx->valide_at), false
                );
                if ($joursAvant >= 0) {
                    $nbRapides++;
                }
                $delais[] = abs($joursAvant);
            }
        }

        $delaiMoyen   = count($delais) > 0 ? array_sum($delais) / count($delais) : 0;
        $montantMoyen = $transactions->isNotEmpty()
            ? $transactions->avg('montant') : 0;

        // Volume mensuel sur les 3 derniers mois
        $volumeMensuel = $user->creanceTransactions()
            ->where('statut', 'valide')
            ->where('created_at', '>=', now()->subMonths(3))
            ->avg(DB::raw('montant')) ?? 0;

        return [
            'nb_creances_total' => $nbTotal,
            'nb_retards'        => $user->creances()->where('jours_retard', '>', 0)->count(),
            'nb_creances_retard_actif' => $nbEnRetard,
            'nb_rapides'        => $nbRapides,
            'delai_moyen'       => round($delaiMoyen, 2),
            'anciennete_mois'   => $this->calculerAncienneteMois($user),
            'montant_moyen'     => round($montantMoyen, 2),
            'volume_mensuel'    => round($volumeMensuel, 2),
            'total_encours'     => round($totalEncours, 2),
            'credit_limite'     => $profil->credit_limite,
        ];
    }

    // ─── Calcul du score ─────────────────────────────────────────────────

    /**
     * Applique la formule heuristique et retourne un score entre 0 et 100.
     */
    private function calculerScore(array $v): int
    {
        $score = self::SCORE_BASE;

        // Malus retards
        $score -= $v['nb_retards'] * self::MALUS_PAR_RETARD;
        $score -= $v['nb_creances_retard_actif'] * self::MALUS_PAR_CREANCE_ACTIVE;

        // Bonus paiements rapides
        $score += $v['nb_rapides'] * self::BONUS_PAIEMENT_RAPIDE;

        // Bonus ancienneté
        $score += (int) floor($v['anciennete_mois'] / self::BONUS_ANCIENNETE_DIVISEUR);

        // Malus ratio endettement
        if ($v['credit_limite'] > 0) {
            $ratio = ($v['total_encours'] / $v['credit_limite']) * 100;
            if ($ratio > 80) {
                $score -= self::MALUS_RATIO_SEUIL_80;
            } elseif ($ratio > 50) {
                $score -= self::MALUS_RATIO_SEUIL_50;
            }
        }

        return max(0, min(100, $score));
    }

    // ─── Niveau risque ───────────────────────────────────────────────────

    public function determinerNiveauRisque(int $score): string
    {
        return match (true) {
            $score >= self::SEUIL_FAIBLE => 'faible',
            $score >= self::SEUIL_MOYEN  => 'moyen',
            default                       => 'eleve',
        };
    }

    // ─── Calcul limite crédit ────────────────────────────────────────────

    private function calculerNouvelleLimit(CreditProfile $profil, string $niveau): float
    {
        $limiteActuelle = (float) $profil->credit_limite;
        if ($limiteActuelle <= 0) {
            return $limiteActuelle;
        }

        return match ($niveau) {
            'faible' => $limiteActuelle,
            'moyen'  => round($limiteActuelle * self::LIMITE_REDUCTION_MOYEN, 2),
            'eleve'  => round($limiteActuelle * self::LIMITE_REDUCTION_ELEVE, 2),
            default  => $limiteActuelle,
        };
    }

    // ─── Règles de blocage automatique ───────────────────────────────────

    private function appliquerReglesBlocage(CreditProfile $profil): void
    {
        if ($profil->niveau_risque === 'eleve' && $profil->credit_limite > 0) {
            $ratio = ($profil->total_encours / $profil->credit_limite) * 100;
            if ($ratio > 50 && ! $profil->est_bloque) {
                $profil->est_bloque    = true;
                $profil->motif_blocage = 'Blocage automatique : risque élevé + ratio endettement > 50%';
                Log::warning('[RiskScoring] Compte bloqué automatiquement', [
                    'user_id' => $profil->user_id,
                    'ratio'   => $ratio,
                ]);
            }
        }
    }

    // ─── Utilitaires ─────────────────────────────────────────────────────

    private function calculerAncienneteMois(User $user): int
    {
        return (int) $user->created_at->diffInMonths(now());
    }

    /**
     * Vérifie si un client peut passer une commande crédit.
     * Retourne null si autorisé, sinon le motif de blocage.
     */
    public function verifierEligibilite(User $user, float $montantDemande): ?string
    {
        $profil = $user->creditProfile;

        if (! $profil) {
            return 'Profil de crédit non initialisé.';
        }

        if ($profil->estActuellementBloque()) {
            return 'Compte temporairement bloqué : ' . ($profil->motif_blocage ?? 'raison inconnue');
        }

        if ($profil->score_fiabilite < 30) {
            return 'Score de fiabilité insuffisant (' . $profil->score_fiabilite . '/100).';
        }

        if ($montantDemande > $profil->credit_disponible) {
            return sprintf(
                'Limite de crédit dépassée. Disponible : %s, Demandé : %s. Augmentez la limite de crédit du client.',
                $this->fmtGnf((float) $profil->credit_disponible),
                $this->fmtGnf((float) $montantDemande)
            );
        }

        // Vérifier anomalies critiques non résolues
        $nbCritiques = $user->anomalies()
            ->where('niveau', 'critique')
            ->where('resolved', false)
            ->count();

        if ($nbCritiques >= 3) {
            return "Compte suspendu pour analyse : {$nbCritiques} anomalies critiques non résolues.";
        }

        return null; // ✅ Eligible
    }
}
