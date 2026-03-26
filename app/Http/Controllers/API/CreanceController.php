<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\SendCreanceReimbursementBatchSubmittedMailJob;
use App\Jobs\SendCreanceReimbursementSubmittedMailJob;
use App\Jobs\SendCreanceBatchValidatedReceiptMailJob;
use App\Jobs\SendCreanceValidatedReceiptMailJob;
use App\Jobs\SendCreanceRejectedMailJob;
use App\Jobs\SendCreanceBatchRejectedMailJob;
use App\Models\Creance;
use App\Models\CreanceTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\CreanceService;
use App\Services\AuditLogService;
use App\Mail\CreanceReimbursementSubmittedMail;
use App\Mail\CreanceReimbursementValidatedReceiptMail;
use App\Services\MdingReceiptPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Enums\RoleEnum;

/**
 * Contrôleur des créances PRO.
 *
 * Routes admin  : création, validation, rejet, gestion limites, blocage
 * Routes client : soumission paiement, consultation créances
 */
class CreanceController extends Controller
{
    public function __construct(
        private readonly CreanceService $service,
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

    private function clientIsAssignedToActor(User $client, User $actor): bool
    {
        if (empty($client->assigned_user)) {
            return false;
        }
        return (string) $client->assigned_user === (string) $actor->id;
    }

    private function fmtGnf(float $v): string
    {
        return number_format((float) $v, 0, '.', ' ') . ' GNF';
    }

    private function appendPotentialExcessNote(
        string $notes,
        float $montantSoumis,
        float $montantImputable,
        float $excedentPotentiel,
    ): string {
        $baseNotes = trim($notes);
        if ($excedentPotentiel <= 0.01) {
            return $baseNotes;
        }

        $systemNote = sprintf(
            'Excédent potentiel à confirmer à la validation admin: montant soumis %s, montant imputable %s, excédent potentiel %s. Si validé, l\'excédent sera crédité dans l\'avoir créance.',
            $this->fmtGnf($montantSoumis),
            $this->fmtGnf($montantImputable),
            $this->fmtGnf($excedentPotentiel),
        );

        return $baseNotes !== '' ? ($baseNotes . "\n" . $systemNote) : $systemNote;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ADMIN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /creances — Créer une créance pour un client PRO
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id'     => ['required', 'uuid', 'exists:users,id'],
            'montant'        => ['required', 'numeric', 'min:1'],
            'description'    => ['required', 'string', 'max:500'],
            'date_echeance'  => ['nullable', 'date', 'after:today'],
            'metadata'       => ['nullable', 'array'],
        ]);

        $client = User::findOrFail($data['client_id']);
        $admin  = Auth::user();

        if ($admin instanceof User && !$this->isSuperAdminUser($admin)) {
            if (!$this->clientIsAssignedToActor($client, $admin)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé: ce client n\'est pas assigné à votre compte.',
                ], 403);
            }
        }

        $metadata = is_array($data['metadata'] ?? null) ? ($data['metadata'] ?? []) : [];
        // Règle métier: un impayé (créance) ne doit pas dépasser la limite de crédit du client.
        // Même si un ancien client/front envoie `bypass_credit_limit`, on l'ignore.
        $bypassRequested = false;

        try {
            $creance = $this->service->creerCreance(
                $client,
                (float) $data['montant'],
                $data['description'],
                $data['date_echeance'] ?? null,
                $admin,
                $metadata,
                $bypassRequested
            );

            return response()->json([
                'success' => true,
                'message' => 'Créance créée avec succès.',
                'data'    => $creance->load(['client', 'transactions']),
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /creances — Liste toutes les créances (paginated)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $withTransactions = $request->has('with_transactions')
            ? $request->boolean('with_transactions')
            : true;

        $with = ['client'];
        if ($withTransactions) {
            $with[] = 'transactions';
        }

        $query = Creance::with($with)
            ->when($request->statut, fn($q) => $q->where('statut', $request->statut))
            ->when($request->client_id, fn($q) => $q->where('user_id', $request->client_id))
            ->when($request->search, fn($q) => $q->where(function ($q) use ($request) {
                $q->where('reference', 'like', "%{$request->search}%")
                  ->orWhereHas('client', fn($u) => $u->where('display_name', 'like', "%{$request->search}%"));
            }))
            ->when($actor instanceof User && !$this->isSuperAdminUser($actor), fn($q) =>
                $q->whereHas('client', fn($u) => $u->where('assigned_user', $actor->id))
            )
            ->orderByDesc('created_at');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->per_page ?? 20),
        ]);
    }

    /**
     * GET /creances/{id} — Détail d'une créance
     */
    public function show(string $id): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $creance = Creance::with([
            'client.creditProfile',
            'transactions.validateur',
            'anomalies',
        ])->findOrFail($id);

        if ($actor instanceof User && !$this->isSuperAdminUser($actor)) {
            $client = $creance->client;
            if (!($client instanceof User) || !$this->clientIsAssignedToActor($client, $actor)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé: cette créance ne vous est pas assignée.',
                ], 403);
            }
        }

        return response()->json(['success' => true, 'data' => $creance]);
    }

    /**
     * DELETE /creances/{id} — Résilier (annuler) puis supprimer une créance
     */
    public function destroy(string $id): JsonResponse
    {
        /** @var User|null $admin */
        $admin = Auth::user();

        $creance = Creance::with('client')->findOrFail($id);

        if ($admin instanceof User && !$this->isSuperAdminUser($admin)) {
            $client = $creance->client;
            if (!($client instanceof User) || !$this->clientIsAssignedToActor($client, $admin)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé: cette créance ne vous est pas assignée.',
                ], 403);
            }
        }

        if ($creance->statut !== 'annulee') {
            $creance->statut = 'annulee';
            $creance->save();
        }

        $creance->delete();

        return response()->json([
            'success' => true,
            'message' => 'Créance résiliée et supprimée avec succès.',
            'data' => [
                'id' => $id,
                'statut' => 'annulee',
                'deleted' => true,
            ],
        ]);
    }

    /**
     * POST /creances/transactions/{id}/valider — Valider un paiement
     */
    public function validerPaiement(string $transactionId): JsonResponse
    {
        $transaction = CreanceTransaction::with('creance.client')->findOrFail($transactionId);
        $admin = Auth::user();

        if ($admin instanceof User && !$this->isSuperAdminUser($admin)) {
            $client = $transaction->creance?->client;
            if (!($client instanceof User) || !$this->clientIsAssignedToActor($client, $admin)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé: cette transaction ne vous est pas assignée.',
                ], 403);
            }
        }

        try {
            $creance = $this->service->validerPaiement($transaction, $admin);

            // Montants (utile pour afficher l'excédent converti en avoir).
            $tx = CreanceTransaction::query()->find($transactionId);
            $montantSoumis = $tx ? (float) $tx->montant : 0.0;
            $montantAvant = $tx ? (float) $tx->montant_avant : 0.0;
            $montantApres = $tx ? (float) $tx->montant_apres : 0.0;
            $montantValide = max(0.0, $montantAvant - $montantApres);
            $avoir = (int) round(max(0.0, $montantSoumis - $montantValide));

            // Notifier le client avec un reçu PDF (queue, best effort).
            try {
                $mode = config('edgpay.credit.receipt_mail_mode', 'queue');
                if ($mode === 'sync') {
                    SendCreanceValidatedReceiptMailJob::dispatchSync($transactionId);
                } else {
                    SendCreanceValidatedReceiptMailJob::dispatch($transactionId);
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur dispatch email (reçu paiement validé): ' . $mailException->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => $avoir > 0
                    ? sprintf(
                        'Paiement validé. Excédent crédité sur l\'avoir créance : %s',
                        $this->fmtGnf((float) $avoir)
                    )
                    : 'Paiement validé avec succès.',
                'avoir_montant' => $avoir,
                'data'    => [
                    'creance'           => $creance,
                    'credit_profile'    => $creance->client->creditProfile,
                    'montant_soumis'    => (int) round($montantSoumis),
                    'montant_valide'    => (int) round($montantValide),
                    'avoir_montant'     => $avoir,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /creances/transactions/{id}/rejeter — Rejeter un paiement
     */
    public function rejeterPaiement(Request $request, string $transactionId): JsonResponse
    {
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:500'],
        ]);

        $transaction = CreanceTransaction::with('creance.client')->findOrFail($transactionId);
        $admin = Auth::user();

        // Idempotence: si déjà rejeté, on répond OK (évite les échecs intermittents en double-clic)
        if ($transaction->statut === 'rejete') {
            return response()->json([
                'success' => true,
                'message' => 'Paiement déjà rejeté.',
                'data' => $transaction,
            ]);
        }

        if ($transaction->statut !== 'en_attente') {
            return response()->json([
                'success' => false,
                'message' => "Transaction déjà traitée (statut: {$transaction->statut}).",
            ], 409);
        }

        if ($admin instanceof User && !$this->isSuperAdminUser($admin)) {
            $client = $transaction->creance?->client;
            if (!($client instanceof User) || !$this->clientIsAssignedToActor($client, $admin)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé: cette transaction ne vous est pas assignée.',
                ], 403);
            }
        }

        try {
            $tx = $this->service->rejeterPaiement($transaction, $admin, $data['motif']);

            // Envoyer un email au client (configurable sync/queue)
            $mode = config('edgpay.credit.rejection_mail_mode', 'queue');
            if ($mode === 'sync') {
                SendCreanceRejectedMailJob::dispatchSync((string) $tx->id);
            } else {
                SendCreanceRejectedMailJob::dispatch((string) $tx->id);
            }
            return response()->json(['success' => true, 'message' => 'Paiement rejeté.', 'data' => $tx]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /creances/transactions/batch/{idempotencyKey}/rejeter — Rejeter en 1 clic une soumission groupée
     *
     * Rejette toutes les transactions en_attente portant la même batch_key.
     */
    public function rejeterPaiementBatch(Request $request, string $idempotencyKey): JsonResponse
    {
        $data = $request->validate([
            'motif' => ['required', 'string', 'max:500'],
        ]);

        /** @var User|null $admin */
        $admin = Auth::user();

        $allTransactions = CreanceTransaction::with('creance.client')
            ->where('batch_key', $idempotencyKey)
            ->orderBy('created_at')
            ->get();

        if ($allTransactions->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Soumission introuvable.',
            ], 404);
        }

        $transactions = $allTransactions->where('statut', 'en_attente')->values();

        // Idempotence: plus rien à rejeter (déjà traité)
        if ($transactions->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Aucune transaction en attente: soumission déjà traitée.',
                'data'    => [
                    'batch_key'      => $idempotencyKey,
                    'rejected_count' => 0,
                    'rejected_ids'   => [],
                ],
            ]);
        }

        if ($admin instanceof User && !$this->isSuperAdminUser($admin)) {
            foreach ($transactions as $tx) {
                $client = $tx->creance?->client;
                if (!($client instanceof User) || !$this->clientIsAssignedToActor($client, $admin)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Non autorisé: une ou plusieurs transactions ne vous sont pas assignées.',
                    ], 403);
                }
            }
        }

        $rejectedIds = [];
        $skippedIds = [];

        foreach ($transactions as $tx) {
            try {
                $this->service->rejeterPaiement($tx, $admin, (string) $data['motif']);
                $rejectedIds[] = $tx->id;
            } catch (\RuntimeException $e) {
                // Tolérer les courses: si déjà traité entre la liste et le lock, on skip.
                if (str_contains($e->getMessage(), 'Transaction déjà traitée')) {
                    $skippedIds[] = $tx->id;
                    continue;
                }

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data'    => [
                        'batch_key'      => $idempotencyKey,
                        'rejected_count' => count($rejectedIds),
                        'rejected_ids'   => $rejectedIds,
                        'skipped_ids'    => $skippedIds,
                        'failed_tx_id'   => $tx->id,
                    ],
                ], 422);
            }
        }

        // Envoyer un email de rejet au(x) client(s) concerné(s) seulement si on a réellement rejeté.
        if (!empty($rejectedIds)) {
            $mode = config('edgpay.credit.rejection_mail_mode', 'queue');
            $clientIds = $allTransactions
                ->pluck('creance.client.id')
                ->filter()
                ->unique()
                ->values();

            foreach ($clientIds as $clientId) {
                if ($mode === 'sync') {
                    SendCreanceBatchRejectedMailJob::dispatchSync((string) $clientId, (string) $idempotencyKey);
                } else {
                    SendCreanceBatchRejectedMailJob::dispatch((string) $clientId, (string) $idempotencyKey);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Soumission rejetée.',
            'data'    => [
                'batch_key'      => $idempotencyKey,
                'rejected_count' => count($rejectedIds),
                'rejected_ids'   => $rejectedIds,
                'skipped_ids'    => $skippedIds,
            ],
        ]);
    }

    /**
     * PUT /credit/clients/{userId}/limite — Modifier la limite de crédit
     */
    public function modifierLimite(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'limite' => ['required', 'numeric', 'min:0'],
            'motif'  => ['nullable', 'string', 'max:500'],
        ]);

        $client = User::findOrFail($userId);
        $admin  = Auth::user();

        if ($admin instanceof User && !$this->isSuperAdminUser($admin)) {
            if (!$this->clientIsAssignedToActor($client, $admin)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé: ce client n\'est pas assigné à votre compte.',
                ], 403);
            }
        }

        $profil = $this->service->modifierLimiteCredit(
            $client,
            (float) $data['limite'],
            $admin,
            $data['motif'] ?? ''
        );

        return response()->json([
            'success' => true,
            'message' => 'Limite mise à jour.',
            'data'    => $profil,
        ]);
    }

    /**
     * POST /credit/clients/{userId}/bloquer — Bloquer un compte
     */
    public function bloquerCompte(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);
        $client = User::findOrFail($userId);
        $this->service->bloquerCompte($client, Auth::user(), $data['motif']);
        return response()->json(['success' => true, 'message' => 'Compte bloqué.']);
    }

    /**
     * POST /credit/clients/{userId}/debloquer — Débloquer un compte
     */
    public function debloquerCompte(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $client = User::findOrFail($userId);
        $this->service->debloquerCompte($client, Auth::user(), $data['note'] ?? '');
        return response()->json(['success' => true, 'message' => 'Compte débloqué.']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  CLIENT
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /mes-creances — Créances du client connecté
     */
    public function mesCreances(Request $request): JsonResponse
    {
        $user = Auth::user();

        $withTransactions = $request->has('with_transactions')
            ? $request->boolean('with_transactions')
            : true;

        $query = Creance::query();
        if ($withTransactions) {
            $query->with('transactions');
        }

        $creances = $query->where('user_id', $user->id)
            ->when($request->statut, function ($q) use ($request) {
                $statut = (string) $request->statut;
                if ($statut === 'en_cours') {
                    return $q->whereIn('statut', ['en_cours', 'partiellement_payee']);
                }
                return $q->where('statut', $statut);
            })
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success'        => true,
            'data'           => $creances,
            'avoir_creance_disponible' => (float) ($user->creditProfile?->avoir_creance_disponible ?? 0),
            'avoir_creance_cumule' => (float) ($user->creditProfile?->avoir_creance_cumule ?? 0),
            'credit_profile' => $user->creditProfile,
        ]);
    }

    /**
     * GET /mes-creances/resume — Résumé global des créances (tous statuts)
     *
     * Objectif : fournir des montants par statut fiables même si /mes-creances
     * est paginé ou filtré côté client.
     */
    public function mesCreancesResume(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $rows = Creance::query()
            ->select([
                'statut',
                DB::raw('COUNT(*) as nb'),
                DB::raw('COALESCE(SUM(montant_restant), 0) as total_restant'),
                DB::raw('COALESCE(SUM(montant_total), 0) as total_montant'),
                DB::raw('COALESCE(SUM(montant_paye), 0) as total_paye'),
            ])
            ->where('user_id', $user->id)
            ->groupBy('statut')
            ->orderBy('statut')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $rows,
            'avoir_creance_disponible' => (float) ($user->creditProfile?->avoir_creance_disponible ?? 0),
            'avoir_creance_cumule' => (float) ($user->creditProfile?->avoir_creance_cumule ?? 0),
            'credit_profile' => $user->creditProfile,
        ]);
    }

    /**
     * GET /mes-creances/{id} — Détail d'une créance du client connecté
     */
    public function mesCreanceDetail(string $id): JsonResponse
    {
        $user = Auth::user();

        $creance = Creance::with('transactions')
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'success'        => true,
            'data'           => $creance,
            'avoir_creance_disponible' => (float) ($user->creditProfile?->avoir_creance_disponible ?? 0),
            'avoir_creance_cumule' => (float) ($user->creditProfile?->avoir_creance_cumule ?? 0),
            'credit_profile' => $user->creditProfile,
        ]);
    }

    /**
     * GET /mes-creances/transactions — Historique des transactions du client connecté
     */
    public function mesTransactions(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = CreanceTransaction::with(['creance', 'validateur', 'client'])
            ->where('user_id', $user->id)
            ->when($request->statut, fn($q) => $q->where('statut', $request->statut))
            ->orderByDesc('valide_at')
            ->orderByDesc('created_at');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->per_page ?? 50),
            'avoir_creance_disponible' => (float) ($user->creditProfile?->avoir_creance_disponible ?? 0),
            'avoir_creance_cumule' => (float) ($user->creditProfile?->avoir_creance_cumule ?? 0),
            'credit_profile' => $user->creditProfile,
        ]);
    }

    /**
     * POST /creances/{id}/payer — Soumettre un paiement
     */
    public function soumettrePaiement(Request $request, string $creanceId): JsonResponse
    {
        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'type'    => ['required', Rule::in(['paiement_total', 'paiement_partiel'])],
            'preuve'  => ['nullable', 'file', 'max:5120'], // 5 MB
            'notes'   => ['nullable', 'string', 'max:1000'],
            'avoir_use' => ['nullable', 'boolean'],
            'avoir_mode' => ['nullable', Rule::in(['auto', 'manuel'])],
            'avoir_montant' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $client  = Auth::user();
        $creance = Creance::where('user_id', $client->id)->findOrFail($creanceId);
        $creditProfile = $client->creditProfile;

        $requestedAmount = (float) $data['montant'];
        $useAvoir = $request->boolean('avoir_use');
        $avoirMode = (string) ($data['avoir_mode'] ?? 'auto');
        $avoirMontant = isset($data['avoir_montant']) ? (float) $data['avoir_montant'] : null;
        $avoirBalanceHint = (float) (($creditProfile?->avoir_creance_disponible ?? 0) ?: 0);
        $remaining = (float) $creance->montant_restant;
        $payable = $requestedAmount;
        $excess = 0.0;
        $type = $data['type'];

        // Si avoir activé: pas d'excédent, on cap juste au restant dû.
        $avoirToUse = 0.0;
        if ($useAvoir) {
            if ($remaining > 0) {
                $requestedAmount = min($requestedAmount, $remaining);
                $payable = $requestedAmount;

                if ($avoirMode === 'manuel') {
                    $avoirToUse = (float) max(0.0, (float) ($avoirMontant ?? 0.0));
                } else {
                    $avoirToUse = $payable;
                }
                $avoirToUse = min($avoirToUse, $payable);
                $avoirToUse = min($avoirToUse, $avoirBalanceHint);
            }
        }

        if (!$useAvoir && $requestedAmount > $remaining && $remaining > 0) {
            $payable = $remaining;
            $excess = $requestedAmount - $remaining;
            $type = 'paiement_total';
        }

        try {
            $avoirUtilise = 0;
            $freshClientProfile = null;

            if ($useAvoir && $avoirToUse > 0.01) {
                $idem = (string) ($request->header('X-Idempotency-Key') ?: Str::uuid()->toString());
                $ref = 'avoir_pay_creance:' . $idem . ':' . (string) $creance->id;

                $avoirResult = $this->service->payerCreanceAvecWallet(
                    $client,
                    $creance,
                    (float) $avoirToUse,
                    $ref,
                    [
                        'creance_id' => (string) $creance->id,
                        'montant_demande' => $requestedAmount,
                        'avoir_mode' => $avoirMode,
                    ]
                );
                $avoirUtilise = (int) ($avoirResult['wallet_debite'] ?? 0);

                // Recharger la créance après application de l'avoir.
                $creance = Creance::where('user_id', $client->id)->findOrFail($creanceId);
                $remaining = (float) $creance->montant_restant;
                $payable = max(0.0, (float) $requestedAmount - (float) $avoirUtilise);
                $freshClientProfile = User::with('creditProfile')->find($client->id)?->creditProfile;
            }

            if ($payable <= 0.01) {
                return response()->json([
                    'success' => true,
                    'message' => 'Paiement effectué via votre avoir créance.',
                    'data' => [
                        'creance_id' => (string) $creance->id,
                        'avoir_montant' => $avoirUtilise,
                        'credit_profile' => $freshClientProfile,
                    ],
                    'avoir_montant' => $avoirUtilise,
                ], 201);
            }

            // Backend expects `type`: paiement_total | paiement_partiel
            if ($remaining > 0 && abs($remaining - $payable) < 0.01) {
                $type = 'paiement_total';
            } else {
                $type = 'paiement_partiel';
            }

            $avoirPotentiel = (int) round(max(0.0, $excess));
            $submissionNotes = $this->appendPotentialExcessNote(
                (string) ($data['notes'] ?? ''),
                (float) $requestedAmount,
                (float) $payable,
                (float) $avoirPotentiel,
            );

            $tx = $this->service->soumettreRembours(
                $client,
                $creance,
                (float) $payable,
                $type,
                $request->file('preuve'),
                $submissionNotes
            );

            $avoir = 0;

            // Notifier par email (queue, best effort) qu'un remboursement a été soumis.
            try {
                $recipients = $this->resolveReimbursementRecipients($client);
                if (!empty($recipients)) {
                    $mode = config('edgpay.credit.reimbursement_mail_mode', 'queue');
                    if ($mode === 'sync') {
                        SendCreanceReimbursementSubmittedMailJob::dispatchSync((string) $tx->id, $recipients);
                    } else {
                        SendCreanceReimbursementSubmittedMailJob::dispatch((string) $tx->id, $recipients);
                    }
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur dispatch email (remboursement soumis): ' . $mailException->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => $avoirPotentiel > 0
                    ? sprintf('Paiement soumis. Excédent en attente de validation admin : %s', $this->fmtGnf((float) $avoirPotentiel))
                    : 'Paiement soumis. En attente de validation admin.',
                'data'    => [
                    'transaction' => $tx,
                    'credit_profile' => $freshClientProfile,
                    'avoir_montant' => $avoirUtilise,
                    'avoir_montant_potentiel' => $avoirPotentiel,
                ],
                'avoir_montant' => $avoirUtilise,
                'avoir_montant_potentiel' => $avoirPotentiel,
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /mes-creances/payer-total — Soumettre un paiement global (répartition automatique)
     *
     * Permet au client de soumettre en une seule action le paiement du total restant
     * (ou d'un montant partiel) sur l'ensemble de ses créances impayées.
     */
    public function soumettrePaiementTotal(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Si absent, le backend soumet le total restant.
            'montant' => ['nullable', 'numeric', 'min:0.01'],
            'preuve'  => ['nullable', 'file', 'max:5120'], // 5 MB
            'notes'   => ['nullable', 'string', 'max:1000'],
            'avoir_use' => ['nullable', 'boolean'],
            'avoir_mode' => ['nullable', Rule::in(['auto', 'manuel'])],
            'avoir_montant' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $client = Auth::user();
        $creditProfile = $client->creditProfile;
        $batchKey = (string) ($request->header('X-Idempotency-Key') ?: Str::uuid()->toString());

        $requestedAmount = isset($data['montant']) ? (float) $data['montant'] : null;
        $useAvoir = $request->boolean('avoir_use');
        $avoirMode = (string) ($data['avoir_mode'] ?? 'auto');
        $avoirMontant = isset($data['avoir_montant']) ? (float) $data['avoir_montant'] : null;
        $avoirBalanceHint = (float) (($creditProfile?->avoir_creance_disponible ?? 0) ?: 0);
        $payable = $requestedAmount;
        $excess = 0.0;

        $avoirToUse = 0.0;

        $totalRestant = (float) Creance::query()
            ->where('user_id', $client->id)
            ->whereNotIn('statut', ['payee', 'annulee'])
            ->sum('montant_restant');

        if ($totalRestant <= 0) {
            return response()->json(['success' => false, 'message' => 'Aucun montant restant à payer.'], 422);
        }

        if ($useAvoir) {
            if ($requestedAmount === null) {
                $requestedAmount = min($totalRestant, $avoirBalanceHint);
            } else {
                $requestedAmount = min($requestedAmount, $totalRestant);
            }

            $payable = $requestedAmount;

            if ($avoirMode === 'manuel') {
                $avoirToUse = (float) max(0.0, (float) ($avoirMontant ?? 0.0));
            } else {
                $avoirToUse = (float) ($payable ?? 0.0);
            }
            $avoirToUse = min($avoirToUse, (float) ($payable ?? 0.0));
            $avoirToUse = min($avoirToUse, $avoirBalanceHint);
        } elseif ($requestedAmount !== null && $requestedAmount > $totalRestant) {
            $payable = $totalRestant;
            $excess = $requestedAmount - $totalRestant;
        }

        try {
            $avoirUtilise = 0;
            $freshClientProfile = null;

            if ($useAvoir && $avoirToUse > 0.01) {
                $idem = (string) ($request->header('X-Idempotency-Key') ?: $batchKey);
                $ref = 'avoir_pay_creance_total:' . $idem;

                $avoirResult = $this->service->payerTotalAvecWallet(
                    $client,
                    (float) $avoirToUse,
                    $ref,
                    [
                        'batch_key' => (string) $batchKey,
                        'montant_demande' => $requestedAmount,
                        'avoir_mode' => $avoirMode,
                    ]
                );
                $avoirUtilise = (int) ($avoirResult['wallet_debite'] ?? 0);

                $payable = $requestedAmount !== null
                    ? max(0.0, (float) $requestedAmount - (float) $avoirUtilise)
                    : null;
                $freshClientProfile = User::with('creditProfile')->find($client->id)?->creditProfile;
            }

            if ($payable !== null && $payable <= 0.01) {
                return response()->json([
                    'success' => true,
                    'message' => 'Paiement effectué via votre avoir créance.',
                    'data' => [
                        'batch_key' => $batchKey,
                        'avoir_montant' => $avoirUtilise,
                        'credit_profile' => $freshClientProfile,
                    ],
                ], 201);
            }

            $avoirPotentiel = (int) round(max(0.0, $excess));
            $submissionNotes = $this->appendPotentialExcessNote(
                (string) ($data['notes'] ?? ''),
                (float) ($requestedAmount ?? $totalRestant),
                (float) ($payable ?? $totalRestant),
                (float) $avoirPotentiel,
            );

            $result = $this->service->soumettreRemboursTotal(
                $client,
                $payable,
                $request->file('preuve'),
                $submissionNotes,
                $batchKey,
            );

            $avoir = 0;

            // Notifier par email (queue, best effort) qu'un remboursement global a été soumis.
            // Un seul email récapitulatif est envoyé aux admins (évite spam en cas de nombreuses créances).
            try {
                $recipients = $this->resolveReimbursementRecipients($client);
                if (!empty($recipients)) {
                    $mode = config('edgpay.credit.reimbursement_mail_mode', 'queue');
                    if ($mode === 'sync') {
                        SendCreanceReimbursementBatchSubmittedMailJob::dispatchSync(
                            (string) $client->id,
                            (string) $batchKey,
                            $recipients,
                        );
                    } else {
                        SendCreanceReimbursementBatchSubmittedMailJob::dispatch(
                            (string) $client->id,
                            (string) $batchKey,
                            $recipients,
                        );
                    }
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur dispatch email (batch paiement global soumis): ' . $mailException->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => $avoirPotentiel > 0
                    ? sprintf(
                        'Paiement global soumis. Excédent en attente de validation admin : %s',
                        $this->fmtGnf((float) $avoirPotentiel)
                    )
                    : 'Paiement global soumis. En attente de validation admin.',
                'data'    => array_merge([
                    'batch_key' => $batchKey,
                    'avoir_montant' => $avoirUtilise,
                    'avoir_montant_potentiel' => $avoirPotentiel,
                    'credit_profile' => $freshClientProfile,
                ], $result),
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /creances/transactions/batch/{idempotencyKey}/valider — Valider en 1 clic une soumission groupée
     *
    * Valide toutes les transactions en_attente portant la même batch_key.
    * Les reçus email sont envoyés en arrière-plan (queue).
     */
    public function validerPaiementBatch(string $idempotencyKey): JsonResponse
    {
        /** @var User|null $admin */
        $admin = Auth::user();

        $transactions = CreanceTransaction::with('creance.client')
            ->where('batch_key', $idempotencyKey)
            ->where('statut', 'en_attente')
            ->orderBy('created_at')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune transaction en attente trouvée pour cette soumission.',
            ], 404);
        }

        if ($admin instanceof User && !$this->isSuperAdminUser($admin)) {
            foreach ($transactions as $tx) {
                $client = $tx->creance?->client;
                if (!($client instanceof User) || !$this->clientIsAssignedToActor($client, $admin)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Non autorisé: une ou plusieurs transactions ne vous sont pas assignées.',
                    ], 403);
                }
            }
        }

        $validatedIds = [];
        $avoirTotal = 0;

        foreach ($transactions as $tx) {
            try {
                $this->service->validerPaiement($tx, $admin);
                $validatedIds[] = $tx->id;

                // Calcul excédent: montant - (montant_avant - montant_apres)
                $freshTx = CreanceTransaction::query()->find($tx->id);
                if ($freshTx instanceof CreanceTransaction) {
                    $montantSoumis = (float) $freshTx->montant;
                    $montantValide = max(0.0, (float) $freshTx->montant_avant - (float) $freshTx->montant_apres);
                    $avoir = max(0.0, $montantSoumis - $montantValide);
                    $avoirTotal += (int) round($avoir);
                }
            } catch (\RuntimeException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data'    => [
                        'batch_key'      => $idempotencyKey,
                        'validated_count'=> count($validatedIds),
                        'validated_ids'  => $validatedIds,
                        'failed_tx_id'   => $tx->id,
                    ],
                ], 422);
            }
        }

        // Notifier le(s) client(s) avec un seul reçu récapitulatif par batch (queue, best effort).
        try {
            $clientIds = collect($transactions)->pluck('user_id')->unique()->filter()->values();
            foreach ($clientIds as $clientId) {
                $mode = config('edgpay.credit.receipt_mail_mode', 'queue');
                if ($mode === 'sync') {
                    SendCreanceBatchValidatedReceiptMailJob::dispatchSync((string) $clientId, (string) $idempotencyKey);
                } else {
                    SendCreanceBatchValidatedReceiptMailJob::dispatch((string) $clientId, (string) $idempotencyKey);
                }
            }
        } catch (\Throwable $mailException) {
            Log::error('Erreur dispatch email (reçu batch): ' . $mailException->getMessage(), [
                'batch_key' => $idempotencyKey,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $avoirTotal > 0
                ? sprintf(
                    'Soumission validée. Excédent crédité sur l\'avoir créance : %s',
                    $this->fmtGnf((float) $avoirTotal)
                )
                : 'Soumission validée avec succès.',
            'avoir_montant' => $avoirTotal,
            'data'    => [
                'batch_key'       => $idempotencyKey,
                'validated_count' => count($validatedIds),
                'validated_ids'   => $validatedIds,
                'avoir_montant'   => $avoirTotal,
            ],
        ]);
    }

    /**
     * Résout les destinataires pour la notification "remboursement soumis".
     * Règle stricte : si le PRO est assigné à un sous-admin, notifier uniquement ce sous-admin.
     * Sinon : CREDIT_REIMBURSEMENT_NOTIFY_EMAILS (.env) > admins credits.manage (incl. super-admins).
     */
    private function resolveReimbursementRecipients(User $client): array
    {
        // Si le remboursement est soumis par un sous-admin, notifier uniquement les super-admins.
        $clientRoleSlug = (string) optional($client->role)->slug;
        if (in_array($clientRoleSlug, [
            RoleEnum::SUPPORT_ADMIN,
            RoleEnum::FINANCE_ADMIN,
            RoleEnum::COMMERCIAL_ADMIN,
        ], true)) {
            return User::whereHas('role', function ($query) {
                $query->where('is_super_admin', true);
            })
                ->whereNotNull('email')
                ->pluck('email')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (!empty($client->assigned_user)) {
            $assigned = User::find($client->assigned_user);
            if ($assigned instanceof User && !empty($assigned->email)) {
                return [$assigned->email];
            }

            return [];
        }

        $configured = config('edgpay.credit.reimbursement_notify_emails', []);
        if (is_array($configured) && !empty($configured)) {
            return array_values(array_unique(array_filter($configured)));
        }

        // Fallback : tous les admins capables de gérer le module crédit.
        // ⚠️ Les sous-admins doivent uniquement recevoir les emails des PRO qui leur sont assignés.
        // Donc: on exclut les rôles sous-admin (support/finance/commercial) du fallback.
        // (Les super-admins et les emails configurés restent prioritaires.)
        return User::whereHas('role', function ($query) {
            $query
                ->where('is_super_admin', true)
                ->orWhere(function ($q2) {
                    $q2
                        ->whereNotIn('slug', [
                            RoleEnum::SUPPORT_ADMIN,
                            RoleEnum::FINANCE_ADMIN,
                            RoleEnum::COMMERCIAL_ADMIN,
                        ])
                        ->whereHas('permissions', function ($q) {
                            $q
                                ->where('slug', 'credits.manage')
                                ->whereIn('role_permissions.access_level', ['oui', 'limité']);
                        });
                });
        })
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * GET /creances/transactions/en-attente — Paiements en attente de validation
     */
    public function transactionsEnAttente(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $query = CreanceTransaction::with([
                'creance:id,reference',
                'client:id,display_name,phone',
            ])
            ->where('statut', 'en_attente')
            ->when($actor instanceof User && !$this->isSuperAdminUser($actor), fn($q) =>
                $q->whereHas('client', fn($u) => $u->where('assigned_user', $actor->id))
            )
            ->orderByDesc('created_at');

        $page = $query->paginate($request->per_page ?? 20);

        // Add flattened fields for frontend convenience/reliability.
        $page->getCollection()->transform(function (CreanceTransaction $tx) {
            $tx->setAttribute('client_name', $tx->client?->display_name);
            $tx->setAttribute('client_phone', $tx->client?->phone);
            $tx->setAttribute('creance_reference', $tx->creance?->reference);
            return $tx;
        });

        return response()->json([
            'success' => true,
            'data'    => $page,
        ]);
    }

    /**
     * GET /creances/transactions/validees — Historique des paiements validés (admin)
     */
    public function transactionsValidees(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = Auth::user();

        $query = CreanceTransaction::with(['creance', 'client', 'validateur'])
            ->where('statut', 'valide')
            ->when($actor instanceof User && !$this->isSuperAdminUser($actor), fn($q) =>
                $q->whereHas('client', fn($u) => $u->where('assigned_user', $actor->id))
            )
            ->orderByDesc('valide_at')
            ->orderByDesc('created_at');

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->per_page ?? 50),
        ]);
    }

    /**
     * GET /creances/transactions/{id}/preuve — Récupérer la pièce jointe (admin)
     */
    public function preuveTransaction(string $transactionId)
    {
        $tx = CreanceTransaction::with('client')->findOrFail($transactionId);

        /** @var User|null $user */
        $user = Auth::user();
        $canManage = $user && (
            $user->isSuperAdmin()
            || $user->hasPermission('credits.manage')
            || $user->hasLimitedPermission('credits.manage')
        );

        if (! $canManage && $tx->user_id !== $user?->id) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé.',
            ], 403);
        }

        if ($canManage && $user instanceof User && !$this->isSuperAdminUser($user)) {
            $client = $tx->client;
            if (!($client instanceof User) || !$this->clientIsAssignedToActor($client, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé: ce client n\'est pas assigné à votre compte.',
                ], 403);
            }
        }

        if (! $tx->preuve_fichier) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune preuve jointe pour cette transaction.',
            ], 404);
        }

        $disk = Storage::disk('private');
        if (! $disk->exists($tx->preuve_fichier)) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier de preuve introuvable.',
            ], 404);
        }

        $filename = basename($tx->preuve_fichier);
        $mime = $tx->preuve_mimetype ?: 'application/octet-stream';

        // Inline pour permettre l'affichage PDF/image dans un viewer.
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ];

        return response()->file($disk->path($tx->preuve_fichier), $headers);
    }
}
