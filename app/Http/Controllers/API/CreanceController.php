<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
        $bypassRequested = filter_var($metadata['bypass_credit_limit'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($bypassRequested) {
            $roleSlug = null;
            try {
                $roleSlug = optional($admin?->role)->slug;
            } catch (\Throwable $e) {
                $roleSlug = null;
            }

            if (! $roleSlug && $admin?->role_id) {
                $roleSlug = Role::whereKey($admin->role_id)->value('slug');
            }

            $allowed = [
                RoleEnum::SUPER_ADMIN,
                RoleEnum::SUPPORT_ADMIN,
                RoleEnum::FINANCE_ADMIN,
                RoleEnum::COMMERCIAL_ADMIN,
            ];

            if (! $roleSlug || ! in_array($roleSlug, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bypass non autorisé pour ce rôle.',
                ], 403);
            }
        }

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

            // Notifier le client avec un reçu PDF (best effort).
            try {
                // Email/PDF can be slow; avoid fatal max_execution_time during validation.
                @set_time_limit(120);

                $txFresh = CreanceTransaction::with(['creance', 'client', 'validateur'])->findOrFail($transactionId);
                $client = $txFresh->client;

                if ($client instanceof User && !empty($client->email)) {
                    $pdfBytes = null;
                    if (class_exists(\Dompdf\Dompdf::class)) {
                        try {
                            $pdfBytes = app(MdingReceiptPdfService::class)->generateForTransaction($txFresh);
                        } catch (\Throwable $pdfException) {
                            Log::error('Erreur génération PDF reçu: ' . $pdfException->getMessage());
                        }
                    } else {
                        Log::warning('dompdf/dompdf non installé: reçu PDF non généré.');
                    }

                    Mail::to($client->email)->send(
                        new CreanceReimbursementValidatedReceiptMail($client, $txFresh->creance, $txFresh, $pdfBytes)
                    );

                    Log::info('[Creance] Email reçu envoyé', [
                        'tx_id' => $txFresh->id,
                        'client_id' => $client->id,
                        'with_pdf' => !empty($pdfBytes),
                    ]);
                } else {
                    Log::warning('[Creance] Email reçu non envoyé (client sans email)', [
                        'tx_id' => $txFresh->id,
                        'client_id' => $txFresh->user_id,
                    ]);
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur envoi email (reçu paiement validé): ' . $mailException->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Paiement validé avec succès.',
                'data'    => [
                    'creance'           => $creance,
                    'credit_profile'    => $creance->client->creditProfile,
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
            return response()->json(['success' => true, 'message' => 'Paiement rejeté.', 'data' => $tx]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
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
            ->when($request->statut, fn($q) => $q->where('statut', $request->statut))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success'        => true,
            'data'           => $creances,
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
        ]);

        $client  = Auth::user();
        $creance = Creance::where('user_id', $client->id)->findOrFail($creanceId);

        try {
            $tx = $this->service->soumettreRembours(
                $client,
                $creance,
                (float) $data['montant'],
                $data['type'],
                $request->file('preuve'),
                $data['notes'] ?? ''
            );

            // Notifier par email (best effort) qu'un remboursement a été soumis.
            try {
                $recipients = $this->resolveReimbursementRecipients($client);
                if (!empty($recipients)) {
                    Mail::to($recipients)->send(
                        new CreanceReimbursementSubmittedMail($client, $creance, $tx)
                    );
                }
            } catch (\Throwable $mailException) {
                Log::error(
                    'Erreur envoi email (remboursement soumis): ' . $mailException->getMessage()
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Paiement soumis. En attente de validation admin.',
                'data'    => $tx,
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Résout les destinataires pour la notification "remboursement soumis".
     * Priorité : CREDIT_REIMBURSEMENT_NOTIFY_EMAILS (.env) > sous-admin assigné > super-admins.
     */
    private function resolveReimbursementRecipients(User $client): array
    {
        $configured = config('edgpay.credit.reimbursement_notify_emails', []);
        if (is_array($configured) && !empty($configured)) {
            return array_values(array_unique(array_filter($configured)));
        }

        if (!empty($client->assigned_user)) {
            $assigned = User::find($client->assigned_user);
            if ($assigned instanceof User && !empty($assigned->email)) {
                return [$assigned->email];
            }
        }

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

    /**
     * GET /creances/transactions/en-attente — Paiements en attente de validation
     */
    public function transactionsEnAttente(Request $request): JsonResponse
    {
        $query = CreanceTransaction::with([
                'creance:id,reference',
                'client:id,display_name,phone',
            ])
            ->where('statut', 'en_attente')
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
        $query = CreanceTransaction::with(['creance', 'client', 'validateur'])
            ->where('statut', 'valide')
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
