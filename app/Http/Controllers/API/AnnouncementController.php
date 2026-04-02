<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementCommentResource;
use App\Http\Resources\AnnouncementResource;
use App\Interfaces\AnnouncementRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Enums\RoleEnum;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Interfaces\SystemSettingRepositoryInterface;
use App\Models\Announcement;
use App\Models\AnnouncementComment;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AnnouncementController extends Controller
{
    private const PUBLICATION_FEE_YEAR_KEY = 'announcement_publication_fee_year_amount';
    private const LEGACY_PUBLICATION_FEE_KEY = 'announcement_publication_fee_amount';
    private const DEFAULT_PUBLICATION_FEE_YEAR_AMOUNT = 100000;
    private const DAYS_PER_YEAR = 365;
    private const CLIENT_TARGET_ROLES = [RoleEnum::CLIENT->value, RoleEnum::PRO];

    protected $announcementRepository;

    public function __construct(
        AnnouncementRepositoryInterface $announcementRepository,
        private readonly WalletService $walletService,
        private readonly SystemSettingRepositoryInterface $systemSettingRepository,
    ) {
        $this->announcementRepository = $announcementRepository;
    }

    /**
     * Liste des annonces
     */

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $status = $request->get('status');
        $requestedModerationStatus = strtolower((string) $request->get('moderation_status', 'all'));
        $moderationStatus = in_array($requestedModerationStatus, Announcement::moderationStatuses(), true)
            || $requestedModerationStatus === 'all'
            ? $requestedModerationStatus
            : 'all';
        $requestedLifecycle = strtolower((string) $request->get('lifecycle', 'active'));
        $lifecycle = in_array($requestedLifecycle, ['active', 'expired', 'all'], true)
            ? $requestedLifecycle
            : 'active';

        if ($user->role->slug !== RoleEnum::SUPER_ADMIN && $lifecycle !== 'active') {
            $lifecycle = 'active';
        }

        if ($user->role->slug !== RoleEnum::SUPER_ADMIN) {
            $moderationStatus = 'all';
        }

        // Récupérer les 20 dernières annonces
        $announcements = $this->announcementRepository->getLatestAnnouncements(
            role: $user->role->slug,
            userId: $user->id,
            status: $status,
            limit: 20,
            lifecycle: $lifecycle,
            moderationStatus: $moderationStatus,
        );

        // Obtenir le nombre d'annonces non lues
        $unreadCount = $this->announcementRepository->getUnreadCount(
            role: $user->role->slug,
            userId: $user->id
        );

        $lifecycleCounts = $user->role->slug === RoleEnum::SUPER_ADMIN
            ? $this->announcementRepository->getLifecycleCounts(
                role: $user->role->slug,
                userId: $user->id,
            )
            : null;

        $moderationCounts = $user->role->slug === RoleEnum::SUPER_ADMIN
            ? $this->announcementRepository->getModerationCounts(
                role: $user->role->slug,
                userId: $user->id,
            )
            : null;

        // Formater la réponse
        $meta = [
            'total' => $announcements->count(),
            'unread_count' => $unreadCount,
            'lifecycle' => $lifecycle,
            'moderation_status' => $moderationStatus,
        ];

        if ($lifecycleCounts !== null) {
            $meta['lifecycle_counts'] = $lifecycleCounts;
        }

        if ($moderationCounts !== null) {
            $meta['moderation_counts'] = $moderationCounts;
        }

        $responseData = [
            'items' => AnnouncementResource::collection($announcements),
            'meta' => $meta,
        ];

        return ApiResponseClass::sendResponse(
            $responseData,
            'Liste des annonces récupérée avec succès'
        );
    }


    /**
     * Créer une annonce
     */
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        $roleSlug = (string) ($user->role?->slug ?? '');
        if (!in_array($roleSlug, [RoleEnum::SUPER_ADMIN, RoleEnum::CLIENT->value], true)) {
            return ApiResponseClass::forbidden('Seuls le super admin et les clients peuvent publier une annonce.');
        }

        $data = $request->validated();
        $media = $request->file('media');
        unset($data['media']);
        $data['author_id'] = $user->id;
        $data['category'] = trim((string) ($data['category'] ?? Announcement::CATEGORY_OTHER));
        $diffusionDurationDays = isset($data['diffusion_duration_days'])
            ? max(1, (int) $data['diffusion_duration_days'])
            : null;

        if ($roleSlug === RoleEnum::CLIENT->value) {
            $data['target_roles'] = self::CLIENT_TARGET_ROLES;

            if ($diffusionDurationDays === null) {
                return ApiResponseClass::sendError(
                    'La durée de diffusion en jours est requise pour publier une annonce client.',
                    ['diffusion_duration_days' => ['La durée de diffusion est obligatoire.']],
                    422
                );
            }
        }

        $data['moderation_status'] = $roleSlug === RoleEnum::CLIENT->value
            ? Announcement::MODERATION_PENDING
            : Announcement::MODERATION_APPROVED;

        $storedMediaPath = null;

        try {
            $result = DB::transaction(function () use ($user, $data, $roleSlug, $diffusionDurationDays, $media, &$storedMediaPath) {
                $pricing = $roleSlug === RoleEnum::CLIENT->value
                    ? $this->resolvePublicationPricing($diffusionDurationDays ?? 0)
                    : [
                        'reference_year_fee_amount' => $this->resolvePublicationFeeYearAmount(),
                        'publication_fee_amount' => 0,
                    ];

                $feeAmount = (int) $pricing['publication_fee_amount'];

                if ($feeAmount > 0) {
                    $superAdmin = $this->resolveSuperAdminReceiver();
                    $this->transferPublicationFee(
                        author: $user,
                        superAdmin: $superAdmin,
                        feeAmount: $feeAmount,
                    );
                }

                if ($media !== null) {
                    $storedMediaPath = Storage::disk('public')->putFile('announcements', $media);
                    $data['media_url'] = asset('storage/' . $storedMediaPath);
                    $data['media_type'] = $media->getMimeType();
                    $data['media_name'] = $media->getClientOriginalName();
                }

                $announcement = $this->announcementRepository->create([
                    ...$data,
                    'publication_fee_amount' => $feeAmount,
                    'diffusion_duration_days' => $diffusionDurationDays,
                    'diffusion_starts_at' => $diffusionDurationDays ? now() : null,
                    'diffusion_ends_at' => $diffusionDurationDays ? now()->copy()->addDays($diffusionDurationDays) : null,
                ]);

                $announcement->loadMissing('author', 'readers');
                $freshUser = User::query()->with('wallet')->find($user->id);

                return [
                    'announcement' => $announcement,
                    'publication_fee_amount' => $feeAmount,
                    'reference_year_fee_amount' => (int) $pricing['reference_year_fee_amount'],
                    'wallet_balance' => (int) ($freshUser?->wallet?->cash_available ?? $freshUser?->solde_portefeuille ?? 0),
                ];
            });
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());

            if (str_contains($message, 'solde insuffisant')) {
                return ApiResponseClass::sendError(
                    'Solde insuffisant pour publier cette annonce.',
                    ['details' => $exception->getMessage()],
                    422
                );
            }

            if (str_contains($message, 'wallet')) {
                return ApiResponseClass::sendError(
                    'Wallet introuvable pour finaliser la publication de l annonce.',
                    ['details' => $exception->getMessage()],
                    422
                );
            }

            if ($storedMediaPath !== null) {
                Storage::disk('public')->delete($storedMediaPath);
            }

            report($exception);

            return ApiResponseClass::serverError('Erreur lors de la publication de l annonce.');
        }

        return ApiResponseClass::sendResponse(
            [
                'announcement' => new AnnouncementResource($result['announcement']),
                'publication_fee_amount' => $result['publication_fee_amount'],
                'reference_year_fee_amount' => $result['reference_year_fee_amount'],
                'wallet_balance' => $result['wallet_balance'],
            ],
            $data['moderation_status'] === Announcement::MODERATION_PENDING
                ? 'Annonce soumise. Elle reste en attente de validation par le super admin.'
                : 'Annonce créée avec succès',
            201
        );
    }

    /**
     * Afficher une annonce
     */
    public function show(string $id): JsonResponse
    {
        $user = Auth::user();
        $announcement = $this->resolveAccessibleAnnouncement($id, $user);

        if ($announcement instanceof JsonResponse) {
            return $announcement;
        }

        return ApiResponseClass::sendResponse(
            new AnnouncementResource($announcement),
            'Annonce récupérée avec succès'
        );
    }

    /**
     * Marquer comme lu
     */
    public function markAsRead(string $id): JsonResponse
    {
        $user = Auth::user();
        $announcement = $this->resolveAccessibleAnnouncement($id, $user);

        if ($announcement instanceof JsonResponse) {
            return $announcement;
        }

        $this->announcementRepository->markAsRead($id, $user->id);

        return ApiResponseClass::sendResponse(
            new AnnouncementResource($announcement->fresh()),
            'Annonce marquée comme lue'
        );
    }

    public function approve(string $id, Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user->role->slug !== RoleEnum::SUPER_ADMIN) {
            return ApiResponseClass::forbidden('Seul le super admin peut approuver une annonce.');
        }

        $announcement = $this->announcementRepository->find($id, $user->id);
        if (!$announcement) {
            return ApiResponseClass::notFound('Annonce non trouvée');
        }

        $validated = $request->validate([
            'moderation_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->announcementRepository->update($id, [
            'moderation_status' => Announcement::MODERATION_APPROVED,
            'moderation_notes' => $validated['moderation_notes'] ?? null,
            'moderated_at' => now(),
            'moderated_by' => $user->id,
        ]);

        return ApiResponseClass::sendResponse(
            new AnnouncementResource($this->announcementRepository->find($id, $user->id)),
            'Annonce approuvée avec succès'
        );
    }

    public function reject(string $id, Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user->role->slug !== RoleEnum::SUPER_ADMIN) {
            return ApiResponseClass::forbidden('Seul le super admin peut rejeter une annonce.');
        }

        $announcement = $this->announcementRepository->find($id, $user->id);
        if (!$announcement) {
            return ApiResponseClass::notFound('Annonce non trouvée');
        }

        $validated = $request->validate([
            'moderation_notes' => ['required', 'string', 'max:1000'],
        ]);

        $this->announcementRepository->update($id, [
            'moderation_status' => Announcement::MODERATION_REJECTED,
            'moderation_notes' => $validated['moderation_notes'],
            'moderated_at' => now(),
            'moderated_by' => $user->id,
        ]);

        return ApiResponseClass::sendResponse(
            new AnnouncementResource($this->announcementRepository->find($id, $user->id)),
            'Annonce rejetée avec succès'
        );
    }

    public function toggleLike(string $id): JsonResponse
    {
        $user = Auth::user();
        $announcement = $this->resolveAccessibleAnnouncement($id, $user);

        if ($announcement instanceof JsonResponse) {
            return $announcement;
        }

        $alreadyLiked = $announcement->likes()->where('user_id', $user->id)->exists();

        if ($alreadyLiked) {
            $announcement->likes()->detach($user->id);
        } else {
            $announcement->likes()->attach($user->id);
        }

        $freshAnnouncement = $this->announcementRepository->find($id, $user->id);

        return ApiResponseClass::sendResponse(
            [
                'liked' => !$alreadyLiked,
                'announcement' => new AnnouncementResource($freshAnnouncement),
            ],
            !$alreadyLiked ? 'Annonce aimée.' : 'J aime retiré.'
        );
    }

    public function comments(string $id): JsonResponse
    {
        $user = Auth::user();
        $announcement = $this->resolveAccessibleAnnouncement($id, $user);

        if ($announcement instanceof JsonResponse) {
            return $announcement;
        }

        $comments = $announcement->comments()
            ->with('author')
            ->latest('created_at')
            ->get();

        return ApiResponseClass::sendResponse(
            [
                'items' => AnnouncementCommentResource::collection($comments),
                'count' => $comments->count(),
            ],
            'Commentaires récupérés avec succès'
        );
    }

    public function storeComment(string $id, Request $request): JsonResponse
    {
        $user = Auth::user();
        $announcement = $this->resolveAccessibleAnnouncement($id, $user);

        if ($announcement instanceof JsonResponse) {
            return $announcement;
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:1000'],
        ]);

        $comment = AnnouncementComment::query()->create([
            'announcement_id' => $announcement->id,
            'author_id' => $user->id,
            'content' => trim((string) $validated['content']),
        ]);

        $freshAnnouncement = $this->announcementRepository->find($id, $user->id);

        return ApiResponseClass::sendResponse(
            [
                'comment' => new AnnouncementCommentResource($comment->load('author')),
                'announcement' => new AnnouncementResource($freshAnnouncement),
            ],
            'Commentaire ajouté avec succès',
            201
        );
    }

    /**
     * Marquer toutes comme lues
     */
    public function markAllAsRead(): JsonResponse
    {
        $user = Auth::user();
        $count = $this->announcementRepository->markAllAsRead($user->role->slug, $user->id);

        return ApiResponseClass::sendResponse(
            ['count' => $count],
            "{$count} annonces marquées comme lues"
        );
    }

    /**
     * Supprimer une annonce
     */
    public function destroy(string $id): JsonResponse
    {
        $user = Auth::user();

        if (($user->role->slug ?? null) !== RoleEnum::SUPER_ADMIN) {
            return ApiResponseClass::forbidden('Accès non autorisé');
        }

        $announcement = $this->announcementRepository->find($id, $user->id);

        if (!$announcement) {
            return ApiResponseClass::notFound('Annonce non trouvée');
        }

        // Vérifier les permissions
        if ($user->role->slug !== RoleEnum::SUPER_ADMIN && $announcement->author_id !== $user->id) {
            return ApiResponseClass::sendError('Vous ne pouvez supprimer que vos propres annonces', 403);
        }

        $this->announcementRepository->delete($id);

        return ApiResponseClass::sendResponse(
            null,
            'Annonce supprimée avec succès'
        );
    }

    /**
     * Statistiques
     */
    public function stats(): JsonResponse
    {
        $user = Auth::user();
        $stats = $this->announcementRepository->getStats($user->role->slug, $user->id);

        $responseData = [
            'total' => $stats['total'],
            'unread' => $stats['unread'],
            'read' => $stats['read'],
            'recent' => AnnouncementResource::collection($stats['recent'])
        ];

        return ApiResponseClass::sendResponse($responseData, 'Statistiques récupérées avec succès');
    }

    private function resolvePublicationFeeYearAmount(): int
    {
        $setting = $this->systemSettingRepository->getByKey(self::PUBLICATION_FEE_YEAR_KEY)
            ?? $this->systemSettingRepository->getByKey(self::LEGACY_PUBLICATION_FEE_KEY);

        if (!$setting) {
            return self::DEFAULT_PUBLICATION_FEE_YEAR_AMOUNT;
        }

        return max(0, (int) round((float) ($setting->value ?? 0)));
    }

    private function resolvePublicationPricing(int $diffusionDurationDays): array
    {
        $referenceYearFeeAmount = $this->resolvePublicationFeeYearAmount();
        $safeDurationDays = max(0, $diffusionDurationDays);

        if ($referenceYearFeeAmount <= 0 || $safeDurationDays <= 0) {
            return [
                'reference_year_fee_amount' => $referenceYearFeeAmount,
                'publication_fee_amount' => 0,
            ];
        }

        $publicationFeeAmount = (int) round(
            ($referenceYearFeeAmount * $safeDurationDays) / self::DAYS_PER_YEAR
        );

        return [
            'reference_year_fee_amount' => $referenceYearFeeAmount,
            'publication_fee_amount' => max(0, $publicationFeeAmount),
        ];
    }

    private function resolveSuperAdminReceiver(): User
    {
        $superAdmin = User::query()
            ->whereHas('role', fn ($query) => $query->where('slug', RoleEnum::SUPER_ADMIN))
            ->with('wallet')
            ->first();

        if (!$superAdmin) {
            throw new \RuntimeException('Aucun super admin disponible pour recevoir les frais de publication.');
        }

        return $superAdmin;
    }

    private function transferPublicationFee(User $author, User $superAdmin, int $feeAmount): void
    {
        $receiverWalletId = (string) ($superAdmin->wallet?->id ?? '');

        if ($receiverWalletId === '') {
            $created = $this->walletService->createWalletForUser($superAdmin->id);
            $receiverWalletId = (string) ($created['wallet']?->id ?? '');
        }

        if ($receiverWalletId === '') {
            throw new \RuntimeException('Wallet du super admin introuvable.');
        }

        $this->walletService->deposit(
            walletId: $receiverWalletId,
            userId: (string) $superAdmin->id,
            amount: $feeAmount,
            description: 'Frais de publication annonce',
            fromUserId: (string) $author->id,
        );
    }

    private function resolveAccessibleAnnouncement(string $id, User $user): Announcement|JsonResponse
    {
        $announcement = $this->announcementRepository->find($id, $user->id);

        if (!$announcement) {
            return ApiResponseClass::notFound('Annonce non trouvée');
        }

        if ($announcement->isExpired()) {
            return ApiResponseClass::notFound('Cette annonce a expiré et n est plus diffusée.');
        }

        $roleSlug = (string) ($user->role?->slug ?? '');

        if (!$announcement->isApproved() && $roleSlug !== RoleEnum::SUPER_ADMIN) {
            return ApiResponseClass::forbidden('Cette annonce est encore en attente de validation.');
        }

        if ($roleSlug !== RoleEnum::SUPER_ADMIN && !$announcement->isForRole($roleSlug)) {
            return ApiResponseClass::forbidden('Accès non autorisé à cette annonce');
        }

        return $announcement;
    }
}
