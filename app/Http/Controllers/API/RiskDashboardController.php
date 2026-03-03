<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AnomalyFlag;
use App\Models\AuditLog;
use App\Models\Creance;
use App\Models\CreditProfile;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\AnomalyDetectionService;
use App\Services\AuditLogService;
use App\Services\FinancialLedgerService;
use App\Services\RiskScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard de monitoring du risque crédit — accès admin uniquement.
 *
 * Métriques disponibles :
 *   - Exposition totale
 *   - Créances en retard
 *   - Clients risque élevé / bloqués
 *   - Score moyen global
 *   - Top clients fiables
 *   - Anomalies actives
 *   - Historique audit
 *   - Ledger client
 */
class RiskDashboardController extends Controller
{
    public function __construct(
        private readonly RiskScoringService      $scoring,
        private readonly FinancialLedgerService  $ledger,
        private readonly AnomalyDetectionService $anomaly,
    ) {}

    private function isSuperAdminUser(?User $user): bool
    {
        if (!($user instanceof User)) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (property_exists($user, 'role') && $user->role) {
            return (bool) ($user->role->is_super_admin ?? false);
        }

        try {
            return (bool) optional($user->role)->is_super_admin;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function assignedUserIdsQuery(User $actor)
    {
        return User::query()->where('assigned_user', $actor->id)->select('id');
    }

    private function assertClientIsAssignedOrSuperAdmin(User $actor, User $client): void
    {
        if ($this->isSuperAdminUser($actor)) {
            return;
        }

        if (empty($client->assigned_user) || (string) $client->assigned_user !== (string) $actor->id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Non autorisé: ce client n\'est pas assigné à votre compte.',
            ], 403));
        }
    }

    // ─── Vue globale ─────────────────────────────────────────────────────

    /**
     * GET /risk/dashboard — Vue d'ensemble du risque crédit
     */
    public function overview(): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        $cacheKey = ($actor instanceof User && !$this->isSuperAdminUser($actor))
            ? 'risk.dashboard.overview.actor.' . $actor->id
            : 'risk.dashboard.overview';

        $data = Cache::remember($cacheKey, now()->addSeconds(25), function () use ($actor) {
            $assignedIds = ($actor instanceof User && !$this->isSuperAdminUser($actor))
                ? $this->assignedUserIdsQuery($actor)
                : null;

            // Métriques agrégées
            $profiles = CreditProfile::query();
            if ($assignedIds) {
                $profiles->whereIn('user_id', $assignedIds);
            }

            $totalExposition = (clone $profiles)->sum('total_encours');
            $totalLimite     = (clone $profiles)->sum('credit_limite');
            $scoreMoyen      = (clone $profiles)->avg('score_fiabilite');

            $creancesQuery = Creance::query();
            if ($assignedIds) {
                $creancesQuery->whereIn('user_id', $assignedIds);
            }

            $creancesEnRetard = (clone $creancesQuery)
                ->where(function ($q) {
                    $q->where('statut', 'en_retard')
                        ->orWhere(function ($qq) {
                            $qq->where('date_echeance', '<', now())
                                ->whereNotIn('statut', ['payee', 'annulee']);
                        });
                })
                ->count();

            $montantEnRetard = (clone $creancesQuery)->where('statut', 'en_retard')->sum('montant_restant');

            $clientsRisqueEleve = (clone $profiles)->where('niveau_risque', 'eleve')->count();
            $clientsBloques     = (clone $profiles)->where('est_bloque', true)->count();

            $anomalies = AnomalyFlag::query();
            if ($assignedIds) {
                $anomalies->whereIn('user_id', $assignedIds);
            }
            $anomaliesActives   = (clone $anomalies)->where('resolved', false)->count();
            $anomaliesCritiques = (clone $anomalies)->where('niveau', 'critique')->where('resolved', false)->count();

            // Taux utilisation crédit
            $tauxUtilisation = $totalLimite > 0
                ? round(($totalExposition / $totalLimite) * 100, 2)
                : 0;

            return [
                'exposition_totale'        => round($totalExposition, 2),
                'limite_totale'            => round($totalLimite, 2),
                'taux_utilisation_pct'     => $tauxUtilisation,
                'creances_en_retard_nb'    => $creancesEnRetard,
                'creances_en_retard_mtnt'  => round($montantEnRetard, 2),
                'clients_risque_eleve'     => $clientsRisqueEleve,
                'clients_bloques'          => $clientsBloques,
                'score_moyen_global'       => round($scoreMoyen, 1),
                'anomalies_actives'        => $anomaliesActives,
                'anomalies_critiques'      => $anomaliesCritiques,
                'calcule_at'               => now()->toISOString(),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── Top 10 clients fiables ───────────────────────────────────────────

    /**
     * GET /risk/top-clients — Top 10 clients avec meilleur score
     */
    public function topClients(): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        $cacheKey = ($actor instanceof User && !$this->isSuperAdminUser($actor))
            ? 'risk.dashboard.top_clients.actor.' . $actor->id
            : 'risk.dashboard.top_clients';

        $clients = Cache::remember($cacheKey, now()->addSeconds(25), function () use ($actor) {
            $q = CreditProfile::with('user:id,display_name,phone,email')
                ->where('score_fiabilite', '>=', 70)
                ->where('est_bloque', false);

            if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
                $q->whereIn('user_id', $this->assignedUserIdsQuery($actor));
            }

            return $q
                ->orderByDesc('score_fiabilite')
                ->limit(10)
                ->get()
                ->map(fn($p) => [
                    'user'              => $p->user,
                    'score'             => $p->score_fiabilite,
                    'niveau_risque'     => $p->niveau_risque,
                    'credit_limite'     => $p->credit_limite,
                    'credit_disponible' => $p->credit_disponible,
                    'total_encours'     => $p->total_encours,
                    'taux_utilisation'  => $p->ratio_endettement,
                ]);
        });

        return response()->json(['success' => true, 'data' => $clients]);
    }

    // ─── Clients à risque élevé ───────────────────────────────────────────

    /**
     * GET /risk/clients-risque — Clients risque élevé ou bloqués
     */
    public function clientsARisque(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $perPage = (int) ($request->per_page ?? 20);
        $page = (int) ($request->page ?? 1);
        $cacheKey = ($actor instanceof User && !$this->isSuperAdminUser($actor))
            ? sprintf('risk.dashboard.clients_risque.actor.%s.%d.%d', $actor->id, $perPage, $page)
            : sprintf('risk.dashboard.clients_risque.%d.%d', $perPage, $page);

        $clients = Cache::remember($cacheKey, now()->addSeconds(25), function () use ($perPage, $actor) {
            $q = CreditProfile::with('user:id,display_name,phone,email')
                ->where(function ($q) {
                    $q->where('niveau_risque', 'eleve')
                      ->orWhere('est_bloque', true);
                });

            if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
                $q->whereIn('user_id', $this->assignedUserIdsQuery($actor));
            }

            return $q->orderByDesc('total_encours')->paginate($perPage);
        });

        return response()->json(['success' => true, 'data' => $clients]);
    }

    // ─── Tous les comptes crédit ───────────────────────────────────────

    /**
     * GET /risk/clients — Liste paginée de tous les clients avec un compte crédit
     *
     * Retourne les infos utiles pour l'admin : montant impayé (total_encours)
     * et limite de crédit.
     */
    public function clients(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $perPage = (int) ($request->per_page ?? 50);
        $page = (int) ($request->page ?? 1);

        $cacheKey = ($actor instanceof User && !$this->isSuperAdminUser($actor))
            ? sprintf('risk.dashboard.clients.actor.%s.%d.%d', $actor->id, $perPage, $page)
            : sprintf('risk.dashboard.clients.%d.%d', $perPage, $page);

        $clients = Cache::remember($cacheKey, now()->addSeconds(25), function () use ($perPage, $actor) {
            $q = CreditProfile::with('user:id,display_name,phone,email');

            if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
                $q->whereIn('user_id', $this->assignedUserIdsQuery($actor));
            }

            $p = $q->orderByDesc('total_encours')->paginate($perPage);

            $p->setCollection(
                $p->getCollection()->map(fn($profile) => [
                    'user'              => $profile->user,
                    'score'             => (int) ($profile->score_fiabilite ?? 0),
                    'niveau_risque'     => $profile->niveau_risque,
                    'credit_limite'     => $profile->credit_limite,
                    'credit_disponible' => $profile->credit_disponible,
                    'total_encours'     => $profile->total_encours,
                    'taux_utilisation'  => $profile->ratio_endettement,
                ])
            );

            return $p;
        });

        return response()->json(['success' => true, 'data' => $clients]);
    }

    // ─── Anomalies actives ───────────────────────────────────────────────

    /**
     * GET /risk/anomalies — Liste des anomalies non résolues
     */
    public function anomalies(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $anomalies = AnomalyFlag::with('user:id,display_name,phone')
            ->where('resolved', false)
            ->when($request->niveau, fn($q) => $q->where('niveau', $request->niveau))
            ->when($actor instanceof User && !$this->isSuperAdminUser($actor), fn($q) =>
                $q->whereIn('user_id', $this->assignedUserIdsQuery($actor))
            )
            ->orderByRaw("FIELD(niveau, 'critique', 'warning', 'info')")
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 20);

        return response()->json(['success' => true, 'data' => $anomalies]);
    }

    /**
     * POST /risk/anomalies/{id}/resoudre — Résoudre une anomalie
     */
    public function resoudreAnomalie(Request $request, string $id): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $anomalie = AnomalyFlag::findOrFail($id);

        if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
            $client = User::find($anomalie->user_id);
            if (!($client instanceof User)) {
                return response()->json(['success' => false, 'message' => 'Client introuvable.'], 404);
            }
            $this->assertClientIsAssignedOrSuperAdmin($actor, $client);
        }

        $this->anomaly->resoudreAnomalie($anomalie, $request->user(), $data['note'] ?? '');
        return response()->json(['success' => true, 'message' => 'Anomalie résolue.']);
    }

    /**
     * POST /risk/clients/{userId}/anomalies/resoudre-critiques — Résoudre toutes les anomalies critiques non résolues d'un client
     */
    public function resoudreAnomaliesCritiquesClient(Request $request, string $userId): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $client = User::findOrFail($userId);
        if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
            $this->assertClientIsAssignedOrSuperAdmin($actor, $client);
        }

        $note = $data['note'] ?? '';

        $txResult = DB::transaction(function () use ($client, $actor, $note) {
            $anomalies = AnomalyFlag::query()
                ->where('user_id', $client->id)
                ->where('niveau', 'critique')
                ->where('resolved', false)
                ->lockForUpdate()
                ->get();

            foreach ($anomalies as $anomalie) {
                $this->anomaly->resoudreAnomalie($anomalie, $actor, $note);
            }

            $resolvedCount = $anomalies->count();

            // Si le compte était bloqué automatiquement, et qu'il ne reste plus
            // d'anomalies critiques non résolues, on débloque le profil.
            $profil = CreditProfile::query()
                ->where('user_id', $client->id)
                ->lockForUpdate()
                ->first();

            $autoUnblocked = false;
            if ($profil instanceof CreditProfile) {
                $remainingCritiques = AnomalyFlag::query()
                    ->where('user_id', $client->id)
                    ->where('niveau', 'critique')
                    ->where('resolved', false)
                    ->count();

                $motif = (string) ($profil->motif_blocage ?? '');
                $wasAutoBlocked = $profil->est_bloque
                    && str_starts_with($motif, 'Blocage automatique');

                if ($wasAutoBlocked && $remainingCritiques === 0) {
                    $profil->update([
                        'est_bloque'      => false,
                        'bloque_jusqu_au' => null,
                        'motif_blocage'   => null,
                    ]);
                    $autoUnblocked = true;
                }
            }

            return [
                'resolved' => $resolvedCount,
                'auto_unblocked' => $autoUnblocked,
            ];
        });

        $resolvedCount = (int) ($txResult['resolved'] ?? 0);
        $autoUnblocked = (bool) ($txResult['auto_unblocked'] ?? false);

        AuditLogService::log(
            AuditLogService::ACTION_ANOMALIE_RESOLUE,
            $client->id,
            User::class,
            'succes',
            null,
            ['resolved_critiques' => $resolvedCount, 'auto_unblocked' => $autoUnblocked],
            ['admin_id' => $actor?->id, 'note' => $note]
        );

        return response()->json([
            'success' => true,
            'message' => $resolvedCount > 0
                ? "$resolvedCount anomalies critiques résolues."
                : 'Aucune anomalie critique à résoudre.',
            'data' => ['resolved' => $resolvedCount, 'auto_unblocked' => $autoUnblocked],
        ]);
    }

    // ─── Profil de risque par client ─────────────────────────────────────

    /**
     * GET /risk/clients/{userId}/profil — Profil de risque complet d'un client
     */
    public function profilClient(string $userId): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $client = User::with([
            'creditProfile',
            'creances' => fn($q) => $q->orderByDesc('created_at')->limit(10),
            'anomalies' => fn($q) => $q->where('resolved', false)->orderByDesc('created_at'),
        ])->findOrFail($userId);

        if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
            $this->assertClientIsAssignedOrSuperAdmin($actor, $client);
        }

        $scoreHistory = $client->creditProfile
            ? \App\Models\CreditScoreHistory::where('user_id', $userId)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
            : collect();

        return response()->json([
            'success' => true,
            'data'    => [
                'client'          => $client,
                'score_history'   => $scoreHistory,
                'creances_stats'  => [
                    'total'       => $client->creances->count(),
                    'en_retard'   => $client->creances->where('statut', 'en_retard')->count(),
                    'payees'      => $client->creances->where('statut', 'payee')->count(),
                ],
            ],
        ]);
    }

    /**
     * POST /risk/clients/{userId}/recalculer-score — Recalcul manuel du score
     */
    public function recalculerScore(string $userId): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $client = User::findOrFail($userId);

        if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
            $this->assertClientIsAssignedOrSuperAdmin($actor, $client);
        }

        $profil = $this->scoring->recalculerScore($client, 'recalcul_manuel_admin');

        // Le listing admin (GET /risk/clients) et autres widgets du dashboard
        // sont mis en cache ~25s. Après un recalcul de score (qui peut ajuster
        // la limite), on invalide le cache pour éviter une incohérence entre
        // admin et côté client.
        try {
            foreach ([50, 200] as $perPage) {
                Cache::forget(sprintf('risk.dashboard.clients.%d.%d', $perPage, 1));
                if ($actor instanceof User && ! $this->isSuperAdminUser($actor)) {
                    Cache::forget(sprintf('risk.dashboard.clients.actor.%s.%d.%d', $actor->id, $perPage, 1));
                }
            }

            foreach ([20, 50] as $perPage) {
                Cache::forget(sprintf('risk.dashboard.clients_risque.%d.%d', $perPage, 1));
                if ($actor instanceof User && ! $this->isSuperAdminUser($actor)) {
                    Cache::forget(sprintf('risk.dashboard.clients_risque.actor.%s.%d.%d', $actor->id, $perPage, 1));
                }
            }

            Cache::forget('risk.dashboard.top_clients');
            if ($actor instanceof User && ! $this->isSuperAdminUser($actor)) {
                Cache::forget('risk.dashboard.top_clients.actor.' . $actor->id);
            }
        } catch (\Throwable) {
            // Best-effort: ne pas bloquer l'action.
        }

        return response()->json([
            'success' => true,
            'message' => 'Score recalculé.',
            'data'    => $profil,
        ]);
    }

    // ─── Ledger client ────────────────────────────────────────────────────

    /**
     * GET /risk/clients/{userId}/ledger — Relevé de compte ledger
     */
    public function ledgerClient(Request $request, string $userId): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $client = User::findOrFail($userId);

        if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
            $this->assertClientIsAssignedOrSuperAdmin($actor, $client);
        }

        $releve = $this->ledger->releve($client, $request->limit ?? 50);

        return response()->json(['success' => true, 'data' => $releve]);
    }

    /**
     * GET /risk/clients/{userId}/ledger/integrite — Vérification intégrité ledger
     */
    public function verifierIntegriteLedger(string $userId): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $client     = User::findOrFail($userId);

        if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
            $this->assertClientIsAssignedOrSuperAdmin($actor, $client);
        }

        $corrompues = $this->ledger->verifierIntegrite($client);

        return response()->json([
            'success'          => true,
            'integrite_ok'     => empty($corrompues),
            'entrees_corrompues' => $corrompues,
        ]);
    }

    // ─── Audit trail ─────────────────────────────────────────────────────

    /**
     * GET /risk/audit — Journal d'audit
     */
    public function auditTrail(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $assignedIds = ($actor instanceof User && !$this->isSuperAdminUser($actor))
            ? $this->assignedUserIdsQuery($actor)
            : null;

        $logs = AuditLog::with('acteur:id,display_name,phone')
            ->when($request->action, fn($q) => $q->where('action', $request->action))
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->cible_id, fn($q) => $q->where('cible_id', $request->cible_id))
            ->when($request->date_debut, fn($q) => $q->where('created_at', '>=', $request->date_debut))
            ->when($request->date_fin, fn($q) => $q->where('created_at', '<=', $request->date_fin))
            ->when($assignedIds, fn($q) => $q->where(function ($qq) use ($assignedIds) {
                $qq->whereIn('user_id', $assignedIds)->orWhereIn('cible_id', $assignedIds);
            }))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 50);

        return response()->json(['success' => true, 'data' => $logs]);
    }

    // ─── Distribution des scores ──────────────────────────────────────────

    /**
     * GET /risk/distribution — Distribution des scores par tranches
     */
    public function distributionScores(): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $query = CreditProfile::query();
        if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
            $query->whereIn('user_id', $this->assignedUserIdsQuery($actor));
        }

        $distribution = $query->select(
            DB::raw("
                CASE
                    WHEN score_fiabilite >= 90 THEN 'excellent (90-100)'
                    WHEN score_fiabilite >= 75 THEN 'bon (75-89)'
                    WHEN score_fiabilite >= 60 THEN 'correct (60-74)'
                    WHEN score_fiabilite >= 50 THEN 'moyen (50-59)'
                    WHEN score_fiabilite >= 30 THEN 'faible (30-49)'
                    ELSE 'critique (0-29)'
                END as tranche
            "),
            DB::raw('COUNT(*) as nb_clients'),
            DB::raw('AVG(credit_limite) as limite_moyenne'),
            DB::raw('AVG(total_encours) as encours_moyen')
        )
        ->groupBy('tranche')
        ->orderBy('tranche')
        ->get();

        return response()->json(['success' => true, 'data' => $distribution]);
    }
}
