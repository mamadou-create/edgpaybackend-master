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
use App\Models\Wallet;
use App\Models\WalletTransaction;
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
                        'Paiement validé. Excédent crédité sur le portefeuille : %s',
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
            'wallet_use' => ['nullable', 'boolean'],
            'wallet_mode' => ['nullable', Rule::in(['auto', 'manuel'])],
            'wallet_montant' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $client  = Auth::user();
        $creance = Creance::where('user_id', $client->id)->findOrFail($creanceId);

        $requestedAmount = (float) $data['montant'];
        $useWallet = $request->boolean('wallet_use');
        $walletMode = (string) ($data['wallet_mode'] ?? 'auto');
        $walletMontant = isset($data['wallet_montant']) ? (float) $data['wallet_montant'] : null;
        $walletBalanceHint = (float) ((($client instanceof User) ? ($client->solde_portefeuille ?? 0) : 0) ?: 0);
        $remaining = (float) $creance->montant_restant;
        $payable = $requestedAmount;
        $excess = 0.0;
        $type = $data['type'];

        // Si wallet activé: pas d'"excédent" (pas de fonds externes), on cap juste au restant.
        $walletToUse = 0.0;
        if ($useWallet) {
            if ($remaining > 0) {
                $requestedAmount = min($requestedAmount, $remaining);
                $payable = $requestedAmount;

                if ($walletMode === 'manuel') {
                    $walletToUse = (float) max(0.0, (float) ($walletMontant ?? 0.0));
                } else {
                    $walletToUse = $payable; // auto: on tente de couvrir le montant demandé
                }
                $walletToUse = min($walletToUse, $payable);
                $walletToUse = min($walletToUse, $walletBalanceHint);
            }
        }

        if (!$useWallet && $requestedAmount > $remaining && $remaining > 0) {
            $payable = $remaining;
            $excess = $requestedAmount - $remaining;
            $type = 'paiement_total';
        }

        try {
            if ($useWallet && $walletToUse > 0.01) {
                $idem = (string) ($request->header('X-Idempotency-Key') ?: Str::uuid()->toString());
                $ref = 'wallet_pay_creance:' . $idem . ':' . (string) $creance->id;

                $this->service->payerCreanceAvecWallet(
                    $client,
                    $creance,
                    (float) $walletToUse,
                    $ref,
                    [
                        'creance_id' => (string) $creance->id,
                        'montant_demande' => $requestedAmount,
                        'wallet_mode' => $walletMode,
                    ]
                );

                // Recharger la créance après application wallet.
                $creance = Creance::where('user_id', $client->id)->findOrFail($creanceId);
                $remaining = (float) $creance->montant_restant;
                $payable = max(0.0, (float) $requestedAmount - (float) $walletToUse);
            }

            if ($payable <= 0.01) {
                return response()->json([
                    'success' => true,
                    'message' => 'Paiement effectué via votre portefeuille (avoir).',
                    'data' => null,
                    'avoir_montant' => 0,
                ], 201);
            }

            // Backend expects `type`: paiement_total | paiement_partiel
            if ($remaining > 0 && abs($remaining - $payable) < 0.01) {
                $type = 'paiement_total';
            } else {
                $type = 'paiement_partiel';
            }

            $tx = $this->service->soumettreRembours(
                $client,
                $creance,
                (float) $payable,
                $type,
                $request->file('preuve'),
                $data['notes'] ?? ''
            );

            $avoir = 0;
            if ($excess > 0) {
                $avoir = $this->creditWalletOverpayment(
                    $client,
                    $excess,
                    'credit_note_overpay_creance_tx:' . (string) $tx->id,
                    [
                        'creance_id' => (string) $creance->id,
                        'transaction_id' => (string) $tx->id,
                        'montant_soumis' => $requestedAmount,
                        'montant_paye' => $payable,
                        'montant_excedent' => $excess,
                    ],
                    'Avoir: excédent paiement créance',
                );
            }

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
                'message' => $avoir > 0
                    ? sprintf('Paiement soumis. Excédent crédité sur votre portefeuille : %s', $this->fmtGnf((float) $avoir))
                    : 'Paiement soumis. En attente de validation admin.',
                'data'    => $tx,
                'avoir_montant' => $avoir,
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
            'wallet_use' => ['nullable', 'boolean'],
            'wallet_mode' => ['nullable', Rule::in(['auto', 'manuel'])],
            'wallet_montant' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $client = Auth::user();
        $batchKey = (string) ($request->header('X-Idempotency-Key') ?: Str::uuid()->toString());

        $requestedAmount = isset($data['montant']) ? (float) $data['montant'] : null;
        $useWallet = $request->boolean('wallet_use');
        $walletMode = (string) ($data['wallet_mode'] ?? 'auto');
        $walletMontant = isset($data['wallet_montant']) ? (float) $data['wallet_montant'] : null;
        $walletBalanceHint = (float) ((($client instanceof User) ? ($client->solde_portefeuille ?? 0) : 0) ?: 0);
        $payable = $requestedAmount;
        $excess = 0.0;

        $walletToUse = 0.0;

        if ($requestedAmount !== null) {
            $totalRestant = (float) Creance::query()
                ->where('user_id', $client->id)
                ->whereNotIn('statut', ['payee', 'annulee'])
                ->sum('montant_restant');

            if ($totalRestant <= 0) {
                return response()->json(['success' => false, 'message' => 'Aucun montant restant à payer.'], 422);
            }

            if ($useWallet) {
                // Avec wallet: cap au total restant (pas d'excédent).
                $requestedAmount = min($requestedAmount, $totalRestant);
                $payable = $requestedAmount;

                if ($walletMode === 'manuel') {
                    $walletToUse = (float) max(0.0, (float) ($walletMontant ?? 0.0));
                } else {
                    $walletToUse = (float) $payable;
                }
                $walletToUse = min($walletToUse, (float) $payable);
                $walletToUse = min($walletToUse, $walletBalanceHint);
            } elseif ($requestedAmount > $totalRestant) {
                $payable = $totalRestant;
                $excess = $requestedAmount - $totalRestant;
            }
        }

        try {
            if ($useWallet && $walletToUse > 0.01) {
                $idem = (string) ($request->header('X-Idempotency-Key') ?: $batchKey);
                $ref = 'wallet_pay_creance_total:' . $idem;

                $this->service->payerTotalAvecWallet(
                    $client,
                    (float) $walletToUse,
                    $ref,
                    [
                        'batch_key' => (string) $batchKey,
                        'montant_demande' => $requestedAmount,
                        'wallet_mode' => $walletMode,
                    ]
                );

                $payable = $requestedAmount !== null
                    ? max(0.0, (float) $requestedAmount - (float) $walletToUse)
                    : null;
            }

            if ($payable !== null && $payable <= 0.01) {
                return response()->json([
                    'success' => true,
                    'message' => 'Paiement effectué via votre portefeuille (avoir).',
                    'data' => ['batch_key' => $batchKey, 'avoir_montant' => 0],
                ], 201);
            }

            $result = $this->service->soumettreRemboursTotal(
                $client,
                $payable,
                $request->file('preuve'),
                $data['notes'] ?? '',
                $batchKey,
            );

            $avoir = 0;
            if ($excess > 0) {
                $avoir = $this->creditWalletOverpayment(
                    $client,
                    $excess,
                    'credit_note_overpay_creance_batch:' . (string) $batchKey,
                    [
                        'batch_key' => (string) $batchKey,
                        'montant_soumis' => $requestedAmount,
                        'montant_paye' => $payable,
                        'montant_excedent' => $excess,
                    ],
                    'Avoir: excédent paiement global (creances)',
                );
            }

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
                'message' => $avoir > 0
                    ? sprintf(
                        'Paiement global soumis. Excédent crédité sur votre portefeuille : %s',
                        $this->fmtGnf((float) $avoir)
                    )
                    : 'Paiement global soumis. En attente de validation admin.',
                'data'    => array_merge(['batch_key' => $batchKey, 'avoir_montant' => $avoir], $result),
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
                    'Soumission validée. Excédent crédité sur le portefeuille : %s',
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
