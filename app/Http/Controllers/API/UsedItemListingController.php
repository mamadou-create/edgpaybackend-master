<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\UsedItemListing\StoreUsedItemListingRequest;
use App\Interfaces\CommissionRepositoryInterface;
use App\Models\UsedItemBid;
use App\Models\UsedItemListing;
use App\Models\User;
use App\Models\Wallet;
use App\Support\PublicMediaUrl;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Throwable;

class UsedItemListingController extends Controller
{
    private const PUBLICATION_FEE_KEY = 'occasion_publication_fee_rate';
    private const STANDARD_PUBLICATION_THRESHOLD = 50000000;
    private const STANDARD_PUBLICATION_MONTHS = 6;
    private const EXTENDED_PUBLICATION_MONTHS = 12;

    public function __construct(
        private readonly WalletService $walletService,
        private readonly CommissionRepositoryInterface $commissionRepository,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', UsedItemListing::STATUS_ACTIVE));
        $saleType = trim((string) $request->query('sale_type', ''));
        $transactionType = trim((string) $request->query('transaction_type', ''));
        $itemCondition = trim((string) $request->query('item_condition', ''));
        $wantedObject = trim((string) $request->query('wanted_object', ''));
        $query = trim((string) $request->query('q', ''));
        $perPage = max(1, min((int) $request->query('per_page', $request->query('limit', 24)), 100));

        $items = UsedItemListing::query()
            ->with(['seller:id,display_name,phone'])
            ->withCount('bids')
            ->withMax('bids', 'amount')
            ->where('moderation_status', UsedItemListing::MODERATION_APPROVED)
            ->where(function ($builder) {
                $builder->whereNull('publication_ends_at')
                    ->orWhere('publication_ends_at', '>', now());
            })
            ->when($status !== 'all', fn ($builder) => $builder->where('status', $status))
            ->when($saleType !== '' && in_array($saleType, UsedItemListing::saleTypes(), true), fn ($builder) => $builder->where('sale_type', $saleType))
            ->when($transactionType !== '' && in_array($transactionType, UsedItemListing::transactionTypes(), true), fn ($builder) => $builder->where('transaction_type', $transactionType))
            ->when($itemCondition !== '', fn ($builder) => $builder->where('item_condition', 'like', "%{$itemCondition}%"))
            ->when($wantedObject !== '', function ($builder) use ($wantedObject) {
                $builder->where(function ($inner) use ($wantedObject) {
                    $inner->where('wanted_object', 'like', "%{$wantedObject}%")
                        ->orWhereJsonContains('wanted_objects', $wantedObject);
                });
            })
            ->when($query !== '', function ($builder) use ($query) {
                $builder->where(function ($inner) use ($query) {
                    $inner->where('title', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%")
                        ->orWhere('category', 'like', "%{$query}%")
                        ->orWhere('address', 'like', "%{$query}%")
                        ->orWhere('city', 'like', "%{$query}%");
                });
            })
            ->orderByRaw("case when sale_type = 'auction' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return ApiResponseClass::sendResponse(
            [
                'items' => $items->getCollection()->map(
                    fn (UsedItemListing $item) => $this->serializeListing($item)
                )->values(),
                'meta' => $this->paginationMeta($items),
            ],
            'Annonces d\'occasion récupérées avec succès'
        );
    }

    public function myListings(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        $perPage = max(1, min((int) $request->query('per_page', 20), 100));

        $items = UsedItemListing::query()
            ->with(['seller:id,display_name,phone'])
            ->withCount('bids')
            ->withMax('bids', 'amount')
            ->where('seller_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return ApiResponseClass::sendResponse(
            [
                'items' => $items->getCollection()->map(
                    fn (UsedItemListing $item) => $this->serializeListing($item)
                )->values(),
                'meta' => $this->paginationMeta($items),
            ],
            'Vos annonces d\'occasion ont été récupérées avec succès'
        );
    }

    public function store(StoreUsedItemListingRequest $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        $validated = $request->validated();
        $normalizedImageUrls = PublicMediaUrl::normalizeMany($validated['image_urls'] ?? []);
        $storedImageUrls = array_map(
            static fn (string $url) => PublicMediaUrl::toStoredValue($url) ?? $url,
            $normalizedImageUrls
        );
        $storedPrimaryImageUrl = PublicMediaUrl::toStoredValue($validated['image_url'] ?? null);

        try {
            $result = DB::transaction(function () use ($user, $validated, $storedImageUrls, $storedPrimaryImageUrl) {
                $transactionType = (string) ($validated['transaction_type'] ?? UsedItemListing::TRANSACTION_TYPE_SALE);
                $acceptsBarter = in_array($transactionType, [
                    UsedItemListing::TRANSACTION_TYPE_BARTER,
                    UsedItemListing::TRANSACTION_TYPE_SALE_OR_BARTER,
                ], true);
                $wantedObjects = collect($validated['wanted_objects'] ?? [])
                    ->map(fn ($item) => trim((string) $item))
                    ->filter(fn ($item) => $item !== '')
                    ->values()
                    ->all();
                $saleType = (string) $validated['sale_type'];
                $feeRate = $this->resolvePublicationFeeRate();
                $baseAmount = $this->resolvePublicationFeeBaseAmount($saleType, $validated);
                $feeAmount = $this->calculatePublicationFeeAmount($baseAmount, $feeRate);
                $publicationEndsAt = $this->resolvePublicationEndsAt(
                    startsAt: now(),
                    baseAmount: $baseAmount,
                );

                if ($feeAmount > 0) {
                    $superAdmin = $this->resolveSuperAdminReceiver();
                    $this->transferPublicationFee(
                        seller: $user,
                        superAdmin: $superAdmin,
                        feeAmount: $feeAmount,
                        baseAmount: $baseAmount,
                        feeRate: $feeRate,
                        category: (string) $validated['category'],
                    );
                }

                $listing = UsedItemListing::query()->create([
                    'seller_id' => $user->id,
                    'title' => trim((string) ($validated['title'] ?? '')) !== '' ? $validated['title'] : $validated['category'],
                    'description' => $validated['description'],
                    'category' => $validated['category'],
                    'condition_label' => $validated['condition_label'],
                    'city' => $validated['city'] ?? null,
                    'address' => $validated['address'],
                    'contact_phone' => $validated['contact_phone'],
                    'contact_email' => $validated['contact_email'] ?? null,
                    'contact_methods' => array_values($validated['contact_methods'] ?? []),
                    'transaction_type' => $transactionType,
                    'accepts_barter' => $acceptsBarter,
                    'wanted_object' => $validated['wanted_object'] ?? null,
                    'wanted_objects' => $wantedObjects,
                    'wanted_category' => $validated['wanted_category'] ?? null,
                    'wanted_value' => $validated['wanted_value'] ?? null,
                    'estimated_object_value' => $validated['estimated_object_value'] ?? null,
                    'accepts_topup' => (bool) ($validated['accepts_topup'] ?? false),
                    'topup_min_amount' => $validated['topup_min_amount'] ?? null,
                    'topup_max_amount' => $validated['topup_max_amount'] ?? null,
                    'max_distance_km' => $validated['max_distance_km'] ?? null,
                    'negotiable' => (bool) ($validated['negotiable'] ?? false),
                    'warranty' => $validated['warranty'] ?? null,
                    'item_condition' => $validated['item_condition'] ?? $validated['condition_label'],
                    'listing_status' => UsedItemListing::STATUS_ACTIVE,
                    'listing_quality_score' => 0,
                    'price' => $saleType === UsedItemListing::SALE_TYPE_FIXED ? (float) ($validated['price'] ?? 0) : null,
                    'sale_type' => $saleType,
                    'starting_bid' => $saleType === UsedItemListing::SALE_TYPE_AUCTION ? (float) ($validated['starting_bid'] ?? 0) : null,
                    'reserve_price' => $saleType === UsedItemListing::SALE_TYPE_AUCTION ? (float) ($validated['reserve_price'] ?? 0) : null,
                    'auction_ends_at' => $saleType === UsedItemListing::SALE_TYPE_AUCTION ? ($validated['auction_ends_at'] ?? null) : null,
                    'image_url' => ($storedImageUrls[0] ?? $storedPrimaryImageUrl),
                    'image_urls' => $storedImageUrls,
                    'publication_fee_rate' => $feeRate,
                    'publication_fee_base_amount' => $baseAmount,
                    'publication_fee_amount' => $feeAmount,
                    'publication_ends_at' => $publicationEndsAt,
                    'status' => UsedItemListing::STATUS_ACTIVE,
                    'moderation_status' => UsedItemListing::MODERATION_PENDING,
                    'admin_notes' => null,
                ]);

                $listing->loadMissing(['seller:id,display_name,phone']);
                $listing->loadCount('bids');
                $listing->loadMax('bids', 'amount');

                $user->refresh();
                $user->loadMissing('wallet');

                return [
                    'listing' => $listing,
                    'publication_fee_rate' => $feeRate,
                    'publication_fee_amount' => $feeAmount,
                    'wallet_balance' => (int) ($user->wallet?->cash_available ?? $user->solde_portefeuille ?? 0),
                ];
            });
        } catch (Throwable $exception) {
            $message = $exception->getMessage();

            if (str_contains(strtolower($message), 'solde insuffisant')) {
                return ApiResponseClass::sendError(
                    'Solde insuffisant pour payer les frais de publication.',
                    ['details' => $message],
                    422
                );
            }

            if (str_contains(strtolower($message), 'wallet')) {
                return ApiResponseClass::sendError(
                    'Wallet introuvable pour finaliser la publication.',
                    ['details' => $message],
                    422
                );
            }

            report($exception);

            return ApiResponseClass::serverError('Erreur lors de la publication de l\'annonce.');
        }

        return ApiResponseClass::created(
            [
                'listing' => $this->serializeListing($result['listing']),
                'publication_fee_rate' => $result['publication_fee_rate'],
                'publication_fee_amount' => $result['publication_fee_amount'],
                'wallet_balance' => $result['wallet_balance'],
            ],
            'Annonce d\'occasion publiée avec succès'
        );
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $path = Storage::disk('public')->putFile('occasions', $validated['image']);
        $publicUrl = PublicMediaUrl::normalize(PublicMediaUrl::fromStoragePath($path));

        return ApiResponseClass::created([
            'path' => $path,
            'url' => $publicUrl,
        ], 'Image occasion envoyée avec succès');
    }

    public function bidHistory(UsedItemListing $listing): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        if (!$this->canAccessListing($user->id, $listing)) {
            return ApiResponseClass::forbidden('Vous ne pouvez pas consulter cette annonce.');
        }

        $bids = $listing->bids()
            ->with(['bidder:id,display_name,phone'])
            ->orderByDesc('amount')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return ApiResponseClass::sendResponse(
            $bids->map(fn (UsedItemBid $bid) => $this->serializeBid($bid))->values(),
            'Historique des enchères récupéré avec succès'
        );
    }

    public function placeBid(Request $request, UsedItemListing $listing): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        if ($listing->sale_type !== UsedItemListing::SALE_TYPE_AUCTION) {
            return ApiResponseClass::sendError('Cette annonce n\'accepte pas les enchères.', null, 422);
        }

        if ((string) $listing->seller_id === (string) $user->id) {
            return ApiResponseClass::forbidden('Vous ne pouvez pas enchérir sur votre propre annonce.');
        }

        if ($listing->moderation_status !== UsedItemListing::MODERATION_APPROVED) {
            return ApiResponseClass::sendError('Cette annonce n\'est pas encore ouverte aux enchères.', null, 422);
        }

        if ($listing->status !== UsedItemListing::STATUS_ACTIVE) {
            return ApiResponseClass::sendError('Cette annonce n\'est plus active.', null, 422);
        }

        if ($this->isPublicationExpired($listing)) {
            return ApiResponseClass::sendError('La durée de diffusion de cette annonce est expirée.', null, 422);
        }

        if ($listing->auction_ends_at !== null && $listing->auction_ends_at->isPast()) {
            return ApiResponseClass::sendError('Cette enchère est déjà terminée.', null, 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $currentAmount = max(
            (float) ($listing->starting_bid ?? 0),
            (float) ($listing->bids()->max('amount') ?? 0)
        );
        $newAmount = (float) $validated['amount'];

        if ($newAmount <= $currentAmount) {
            return ApiResponseClass::sendError(
                'Votre enchère doit être supérieure au montant actuel.',
                ['current_highest_bid' => $currentAmount],
                422
            );
        }

        try {
            $result = DB::transaction(function () use ($listing, $user, $newAmount) {
                /** @var UsedItemListing|null $lockedListing */
                $lockedListing = UsedItemListing::query()
                    ->whereKey($listing->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedListing) {
                    throw new \RuntimeException('Annonce introuvable.');
                }

                if ($lockedListing->sale_type !== UsedItemListing::SALE_TYPE_AUCTION) {
                    throw new \RuntimeException('Cette annonce n\'accepte pas les enchères.');
                }
                if ((string) $lockedListing->seller_id === (string) $user->id) {
                    throw new \RuntimeException('Vous ne pouvez pas enchérir sur votre propre annonce.');
                }
                if ($lockedListing->moderation_status !== UsedItemListing::MODERATION_APPROVED) {
                    throw new \RuntimeException('Cette annonce n\'est pas encore ouverte aux enchères.');
                }
                if ($lockedListing->status !== UsedItemListing::STATUS_ACTIVE) {
                    throw new \RuntimeException('Cette annonce n\'est plus active.');
                }
                if ($this->isPublicationExpired($lockedListing)) {
                    throw new \RuntimeException('La durée de diffusion de cette annonce est expirée.');
                }
                if ($lockedListing->auction_ends_at !== null && $lockedListing->auction_ends_at->isPast()) {
                    throw new \RuntimeException('Cette enchère est déjà terminée.');
                }

                $previousTopBid = UsedItemBid::query()
                    ->where('listing_id', $lockedListing->id)
                    ->orderByDesc('amount')
                    ->orderByDesc('created_at')
                    ->lockForUpdate()
                    ->first();

                $currentTopAmount = max(
                    (float) ($lockedListing->starting_bid ?? 0),
                    (float) ($previousTopBid?->amount ?? 0)
                );

                if ($newAmount <= $currentTopAmount) {
                    throw new \RuntimeException('Votre enchère doit être supérieure au montant actuel.');
                }

                $bidderWallet = Wallet::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (!$bidderWallet) {
                    throw new \RuntimeException('Wallet introuvable pour placer une enchère.');
                }

                $bidderWasTop = $previousTopBid !== null
                    && (string) $previousTopBid->bidder_id === (string) $user->id;

                $amountToBlock = $bidderWasTop
                    ? max(0, (int) ceil($newAmount - (float) $previousTopBid->amount))
                    : max(0, (int) ceil($newAmount));

                $blockedBefore = (int) $bidderWallet->blocked_amount;
                $availableBefore = (int) $bidderWallet->cash_available - $blockedBefore;
                if ($availableBefore < $amountToBlock) {
                    throw new \RuntimeException(
                        "Solde disponible insuffisant. Disponible: {$availableBefore} GNF, requis: {$amountToBlock} GNF"
                    );
                }

                if ($amountToBlock > 0) {
                    $bidderWallet->blocked_amount += $amountToBlock;
                    $bidderWallet->save();
                }

                $bid = UsedItemBid::query()->create([
                    'listing_id' => $lockedListing->id,
                    'bidder_id' => $user->id,
                    'amount' => $newAmount,
                ]);

                $releasedForPreviousTopBidder = 0;
                if ($previousTopBid !== null && !$bidderWasTop) {
                    $previousWallet = Wallet::query()
                        ->where('user_id', $previousTopBid->bidder_id)
                        ->lockForUpdate()
                        ->first();

                    if ($previousWallet) {
                        $unlockAmount = min(
                            (int) $previousWallet->blocked_amount,
                            max(0, (int) ceil((float) $previousTopBid->amount))
                        );

                        if ($unlockAmount > 0) {
                            $previousWallet->blocked_amount -= $unlockAmount;
                            $previousWallet->save();
                            $releasedForPreviousTopBidder = $unlockAmount;
                        }
                    }
                }

                $bidderWallet->refresh();
                $blockedAfter = (int) $bidderWallet->blocked_amount;
                $availableAfter = (int) $bidderWallet->cash_available - $blockedAfter;

                $bid->loadMissing(['bidder:id,display_name,phone']);
                $lockedListing->loadMissing(['seller:id,display_name,phone']);
                $lockedListing->loadCount('bids');
                $lockedListing->loadMax('bids', 'amount');

                return [
                    'bid' => $bid,
                    'listing' => $lockedListing->fresh()
                        ->load(['seller:id,display_name,phone'])
                        ->loadCount('bids')
                        ->loadMax('bids', 'amount'),
                    'wallet' => [
                        'cash_available' => (int) $bidderWallet->cash_available,
                        'blocked_before' => $blockedBefore,
                        'blocked_after' => $blockedAfter,
                        'available_before' => $availableBefore,
                        'available_after' => $availableAfter,
                        'amount_blocked_for_bid' => $amountToBlock,
                        'released_for_previous_top_bidder' => $releasedForPreviousTopBidder,
                        'was_already_top_bidder' => $bidderWasTop,
                    ],
                ];
            });
        } catch (\RuntimeException $exception) {
            return ApiResponseClass::sendError($exception->getMessage(), null, 422);
        } catch (Throwable $exception) {
            report($exception);
            return ApiResponseClass::serverError('Erreur lors de l enregistrement de l enchère.');
        }

        return ApiResponseClass::created([
            'bid' => $this->serializeBid($result['bid']),
            'listing' => $this->serializeListing($result['listing']),
            'wallet' => $result['wallet'],
        ], 'Enchère enregistrée avec succès');
    }

    public function updateStatus(Request $request, UsedItemListing $listing): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        if ((string) $listing->seller_id !== (string) $user->id) {
            return ApiResponseClass::forbidden('Vous ne pouvez modifier que vos propres annonces.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', UsedItemListing::statuses())],
        ]);

        $listing->status = (string) $validated['status'];
        $listing->save();
        $listing->loadMissing(['seller:id,display_name,phone']);
        $listing->loadCount('bids');
        $listing->loadMax('bids', 'amount');

        return ApiResponseClass::sendResponse(
            ['listing' => $this->serializeListing($listing)],
            'Statut de l\'annonce mis à jour avec succès'
        );
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $admin = Auth::user();
        if (!$admin) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        if (($admin->role->slug ?? null) !== 'super_admin') {
            return ApiResponseClass::forbidden('Accès réservé au super admin.');
        }

        $moderationStatus = trim((string) $request->query('moderation_status', 'all'));
        $status = trim((string) $request->query('status', 'all'));
        $query = trim((string) $request->query('q', ''));

        $items = UsedItemListing::query()
            ->with(['seller:id,display_name,phone'])
            ->withCount('bids')
            ->withMax('bids', 'amount')
            ->when($moderationStatus !== 'all', fn ($builder) => $builder->where('moderation_status', $moderationStatus))
            ->when($status !== 'all', fn ($builder) => $builder->where('status', $status))
            ->when($query !== '', function ($builder) use ($query) {
                $builder->where(function ($inner) use ($query) {
                    $inner->where('title', 'like', "%{$query}%")
                        ->orWhere('category', 'like', "%{$query}%")
                        ->orWhere('address', 'like', "%{$query}%")
                        ->orWhere('city', 'like', "%{$query}%")
                        ->orWhereHas('seller', function ($sellerQuery) use ($query) {
                            $sellerQuery->where('display_name', 'like', "%{$query}%")
                                ->orWhere('phone', 'like', "%{$query}%");
                        });
                });
            })
            ->orderByRaw("case moderation_status when 'pending' then 0 when 'rejected' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->limit(150)
            ->get();

        return ApiResponseClass::sendResponse(
            $items->map(fn (UsedItemListing $item) => $this->serializeListing($item))->values(),
            'Annonces occasion admin récupérées avec succès'
        );
    }

    public function adminUpdate(Request $request, UsedItemListing $listing): JsonResponse
    {
        $admin = Auth::user();
        if (!$admin) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        if (($admin->role->slug ?? null) !== 'super_admin') {
            return ApiResponseClass::forbidden('Accès réservé au super admin.');
        }

        $validated = $request->validate([
            'moderation_status' => ['nullable', 'string', 'in:' . implode(',', UsedItemListing::moderationStatuses())],
            'status' => ['nullable', 'string', 'in:' . implode(',', UsedItemListing::statuses())],
            'admin_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        try {
            $listing = DB::transaction(function () use ($listing, $validated) {
                /** @var UsedItemListing $lockedListing */
                $lockedListing = UsedItemListing::query()
                    ->with(['seller:id,display_name,phone'])
                    ->whereKey($listing->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (array_key_exists('moderation_status', $validated)) {
                    $lockedListing->moderation_status = (string) $validated['moderation_status'];
                }
                if (array_key_exists('status', $validated)) {
                    $lockedListing->status = (string) $validated['status'];
                }
                if (array_key_exists('admin_notes', $validated)) {
                    $lockedListing->admin_notes = $validated['admin_notes'];
                }

                if ($this->shouldRefundPublicationFee($lockedListing)) {
                    $this->refundPublicationFee($lockedListing);
                }

                $lockedListing->save();
                $lockedListing->loadCount('bids');
                $lockedListing->loadMax('bids', 'amount');

                return $lockedListing;
            });
        } catch (Throwable $exception) {
            $message = strtolower($exception->getMessage());

            if (str_contains($message, 'solde insuffisant')) {
                return ApiResponseClass::sendError(
                    'Le remboursement automatique a échoué car le wallet du super admin est insuffisant.',
                    ['details' => $exception->getMessage()],
                    422
                );
            }

            if (str_contains($message, 'wallet')) {
                return ApiResponseClass::sendError(
                    'Le remboursement automatique a échoué car un wallet requis est introuvable.',
                    ['details' => $exception->getMessage()],
                    422
                );
            }

            report($exception);

            return ApiResponseClass::serverError('Erreur lors de la modération de l\'annonce.');
        }

        return ApiResponseClass::sendResponse(
            ['listing' => $this->serializeListing($listing)],
            'Annonce occasion modérée avec succès'
        );
    }

    public function destroy(UsedItemListing $listing): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }

        if ((string) $listing->seller_id !== (string) $user->id) {
            return ApiResponseClass::forbidden('Vous ne pouvez supprimer que vos propres annonces.');
        }

        $listing->delete();

        return ApiResponseClass::sendResponse(null, 'Annonce supprimée avec succès');
    }

    private function canAccessListing(string $userId, UsedItemListing $listing): bool
    {
        return $listing->moderation_status === UsedItemListing::MODERATION_APPROVED
            || (string) $listing->seller_id === $userId;
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more_pages' => $paginator->hasMorePages(),
        ];
    }

    private function serializeListing(UsedItemListing $listing): array
    {
        $currentHighestBid = max(
            (float) ($listing->starting_bid ?? 0),
            (float) (($listing->bids_max_amount ?? null) ?? 0)
        );
        $publicationBaseAmount = (float) ($listing->publication_fee_base_amount ?? 0);
        $publicationDurationMonths = $this->resolvePublicationDurationMonths($publicationBaseAmount);
        $isExpired = $this->isPublicationExpired($listing);

        return [
            'id' => (string) $listing->id,
            'seller_id' => (string) $listing->seller_id,
            'seller_name' => $listing->seller?->display_name ?? 'Utilisateur',
            'seller_phone' => $listing->seller?->phone,
            'title' => $listing->title,
            'description' => $listing->description,
            'category' => $listing->category,
            'condition_label' => $listing->condition_label,
            'city' => $listing->city,
            'address' => $listing->address,
            'contact_phone' => $listing->contact_phone,
            'contact_email' => $listing->contact_email,
            'contact_methods' => array_values($listing->contact_methods ?? []),
            'transaction_type' => $listing->transaction_type ?? UsedItemListing::TRANSACTION_TYPE_SALE,
            'accepts_barter' => (bool) ($listing->accepts_barter ?? false),
            'wanted_object' => $listing->wanted_object,
            'wanted_objects' => array_values($listing->wanted_objects ?? []),
            'wanted_category' => $listing->wanted_category,
            'wanted_value' => $listing->wanted_value,
            'estimated_object_value' => $listing->estimated_object_value,
            'accepts_topup' => (bool) ($listing->accepts_topup ?? false),
            'topup_min_amount' => $listing->topup_min_amount,
            'topup_max_amount' => $listing->topup_max_amount,
            'max_distance_km' => $listing->max_distance_km,
            'negotiable' => (bool) ($listing->negotiable ?? false),
            'warranty' => $listing->warranty,
            'item_condition' => $listing->item_condition ?? $listing->condition_label,
            'listing_status' => $listing->listing_status ?? $listing->status,
            'listing_quality_score' => (float) ($listing->listing_quality_score ?? 0),
            'price' => $listing->price,
            'sale_type' => $listing->sale_type,
            'starting_bid' => $listing->starting_bid,
            'reserve_price' => $listing->reserve_price,
            'auction_ends_at' => $listing->auction_ends_at?->toIso8601String(),
            'image_url' => PublicMediaUrl::normalize($listing->image_url),
            'image_urls' => PublicMediaUrl::normalizeMany($listing->image_urls ?? array_filter([$listing->image_url])),
            'publication_fee_rate' => (float) ($listing->publication_fee_rate ?? 0),
            'publication_fee_base_amount' => (float) ($listing->publication_fee_base_amount ?? 0),
            'publication_fee_amount' => (float) ($listing->publication_fee_amount ?? 0),
            'publication_fee_refunded_amount' => (float) ($listing->publication_fee_refunded_amount ?? 0),
            'publication_fee_refunded_at' => $listing->publication_fee_refunded_at?->toIso8601String(),
            'publication_ends_at' => $listing->publication_ends_at?->toIso8601String(),
            'publication_duration_months' => $publicationDurationMonths,
            'is_expired' => $isExpired,
            'status' => $listing->status,
            'moderation_status' => $listing->moderation_status,
            'admin_notes' => $listing->admin_notes,
            'current_highest_bid' => $listing->sale_type === UsedItemListing::SALE_TYPE_AUCTION ? $currentHighestBid : $listing->price,
            'bid_count' => (int) ($listing->bids_count ?? 0),
            'reserve_reached' => $listing->sale_type === UsedItemListing::SALE_TYPE_AUCTION
                ? ($listing->reserve_price === null ? false : $currentHighestBid >= (float) $listing->reserve_price)
                : false,
            'created_at' => $listing->created_at?->toIso8601String(),
            'updated_at' => $listing->updated_at?->toIso8601String(),
        ];
    }

    private function resolvePublicationFeeRate(): float
    {
        $commission = $this->commissionRepository->getByKey(self::PUBLICATION_FEE_KEY);
        $rawValue = (float) ($commission?->value ?? 0);

        if ($rawValue <= 0) {
            return 0.0;
        }

        if ($rawValue > 1) {
            $rawValue = $rawValue / 100;
        }

        return round(min(max($rawValue, 0), 1), 6);
    }

    private function resolvePublicationFeeBaseAmount(string $saleType, array $validated): float
    {
        return $saleType === UsedItemListing::SALE_TYPE_AUCTION
            ? (float) ($validated['starting_bid'] ?? 0)
            : (float) ($validated['price'] ?? 0);
    }

    private function calculatePublicationFeeAmount(float $baseAmount, float $feeRate): int
    {
        if ($baseAmount <= 0 || $feeRate <= 0) {
            return 0;
        }

        return max(0, (int) round($baseAmount * $feeRate));
    }

    private function resolvePublicationDurationMonths(float $baseAmount): int
    {
        return $baseAmount > self::STANDARD_PUBLICATION_THRESHOLD
            ? self::EXTENDED_PUBLICATION_MONTHS
            : self::STANDARD_PUBLICATION_MONTHS;
    }

    private function resolvePublicationEndsAt(Carbon $startsAt, float $baseAmount): Carbon
    {
        return $startsAt->copy()->addMonthsNoOverflow(
            $this->resolvePublicationDurationMonths($baseAmount)
        );
    }

    private function isPublicationExpired(UsedItemListing $listing): bool
    {
        return $listing->publication_ends_at !== null
            && $listing->publication_ends_at->isPast();
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

    private function transferPublicationFee(
        User $seller,
        User $superAdmin,
        int $feeAmount,
        float $baseAmount,
        float $feeRate,
        string $category,
    ): void {
        $receiverWalletId = (string) ($superAdmin->wallet?->id ?? '');

        if ($receiverWalletId === '') {
            $created = $this->walletService->createWalletForUser($superAdmin->id);
            $receiverWalletId = (string) ($created['wallet']?->id ?? '');
        }

        if ($receiverWalletId === '') {
            throw new \RuntimeException('Wallet du super admin introuvable.');
        }

        $description = sprintf(
            'Frais de publication occasion %.2f%% pour %s',
            $feeRate * 100,
            $category
        );

        $this->walletService->deposit(
            walletId: $receiverWalletId,
            userId: (string) $superAdmin->id,
            amount: $feeAmount,
            description: $description,
            fromUserId: (string) $seller->id,
        );
    }

    private function shouldRefundPublicationFee(UsedItemListing $listing): bool
    {
        return $listing->moderation_status === UsedItemListing::MODERATION_REJECTED
            && (float) ($listing->publication_fee_amount ?? 0) > 0
            && $listing->publication_fee_refunded_at === null;
    }

    private function refundPublicationFee(UsedItemListing $listing): void
    {
        $seller = $listing->seller;
        if (!$seller) {
            throw new \RuntimeException('Utilisateur vendeur introuvable pour rembourser les frais de publication.');
        }

        $superAdmin = $this->resolveSuperAdminReceiver();
        $receiverWalletId = (string) ($seller->wallet?->id ?? '');

        if ($receiverWalletId === '') {
            $created = $this->walletService->createWalletForUser($seller->id);
            $receiverWalletId = (string) ($created['wallet']?->id ?? '');
        }

        if ($receiverWalletId === '') {
            throw new \RuntimeException('Wallet du vendeur introuvable.');
        }

        $refundAmount = max(0, (int) round((float) ($listing->publication_fee_amount ?? 0)));
        if ($refundAmount <= 0) {
            return;
        }

        $description = sprintf(
            'Remboursement des frais de publication occasion pour %s après rejet',
            $listing->category
        );

        $this->walletService->deposit(
            walletId: $receiverWalletId,
            userId: (string) $seller->id,
            amount: $refundAmount,
            description: $description,
            fromUserId: (string) $superAdmin->id,
        );

        $listing->publication_fee_refunded_amount = $refundAmount;
        $listing->publication_fee_refunded_at = now();
    }

    private function serializeBid(UsedItemBid $bid): array
    {
        return [
            'id' => (string) $bid->id,
            'listing_id' => (string) $bid->listing_id,
            'bidder_id' => (string) $bid->bidder_id,
            'bidder_name' => $bid->bidder?->display_name ?? 'Utilisateur',
            'bidder_phone' => $bid->bidder?->phone,
            'amount' => (float) $bid->amount,
            'created_at' => $bid->created_at?->toIso8601String(),
        ];
    }
}