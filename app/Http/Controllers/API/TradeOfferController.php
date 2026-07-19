<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\TradeOffer\StoreTradeOfferRequest;
use App\Http\Requests\TradeOffer\UpdateTradeOfferStatusRequest;
use App\Models\TradeOffer;
use App\Models\TradeOfferStatusHistory;
use App\Models\UsedItemListing;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TradeOfferController extends Controller
{
    public function index(Request $request, UsedItemListing $listing): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        $status = trim((string) $request->query('status', ''));
        $limit = max(1, min((int) $request->query('limit', 50), 100));

        $query = TradeOffer::query()
            ->where('listing_id', $listing->id)
            ->where(function ($builder) use ($user, $listing) {
                $builder
                    ->where('proposer_id', $user->id)
                    ->orWhere('recipient_id', $user->id)
                    ->orWhere('recipient_id', $listing->seller_id);
            })
            ->with([
                'items',
                'proposer:id,display_name,phone,email',
                'recipient:id,display_name,phone,email',
            ])
            ->orderByDesc('created_at');

        if ($status !== '' && in_array($status, TradeOffer::statuses(), true)) {
            $query->where('status', $status);
        }

        $items = $query->limit($limit)->get();

        return ApiResponseClass::sendResponse(
            $items->map(fn (TradeOffer $offer) => $this->serializeOffer($offer))->values(),
            'Propositions d\'échange récupérées avec succès'
        );
    }

    public function store(StoreTradeOfferRequest $request, UsedItemListing $listing): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if ((string) $listing->seller_id === (string) $user->id) {
            return ApiResponseClass::forbidden('Vous ne pouvez pas proposer un échange sur votre propre annonce.');
        }

        if (($listing->transaction_type ?? UsedItemListing::TRANSACTION_TYPE_SALE) === UsedItemListing::TRANSACTION_TYPE_SALE) {
            return ApiResponseClass::sendError('Cette annonce est configurée en vente uniquement.', null, 422);
        }

        $validated = $request->validated();
        $rawItems = $validated['items'] ?? [];
        $offeredEstimatedValue = collect($rawItems)
            ->sum(fn ($item) => (float) ($item['estimated_value'] ?? 0));

        $offer = DB::transaction(function () use ($validated, $rawItems, $listing, $user, $offeredEstimatedValue) {
            /** @var TradeOffer $offer */
            $offer = TradeOffer::query()->create([
                'listing_id' => $listing->id,
                'proposer_id' => $user->id,
                'recipient_id' => $listing->seller_id,
                'offered_estimated_value' => $offeredEstimatedValue,
                'requested_estimated_value' => (float) ($validated['requested_estimated_value'] ?? 0),
                'cash_complement' => (float) ($validated['cash_complement'] ?? 0),
                'compatibility_score' => isset($validated['compatibility_score'])
                    ? (float) $validated['compatibility_score']
                    : null,
                'comment' => $validated['comment'] ?? null,
                'status' => TradeOffer::STATUS_PENDING,
                'expires_at' => isset($validated['expires_in_hours'])
                    ? now()->addHours((int) $validated['expires_in_hours'])
                    : null,
            ]);

            foreach ($rawItems as $item) {
                $offer->items()->create([
                    'listing_id' => $item['listing_id'] ?? null,
                    'title' => $item['title'],
                    'category' => $item['category'] ?? null,
                    'condition_label' => $item['condition_label'] ?? null,
                    'estimated_value' => isset($item['estimated_value'])
                        ? (float) $item['estimated_value']
                        : null,
                    'metadata' => $item['metadata'] ?? null,
                ]);
            }

            $this->recordStatusHistory(
                tradeOffer: $offer,
                fromStatus: null,
                toStatus: TradeOffer::STATUS_PENDING,
                changedBy: (string) $user->id,
                note: 'Création de la proposition',
                metadata: null,
            );

            return $offer;
        });

        $offer->load(['items', 'proposer:id,display_name,phone,email', 'recipient:id,display_name,phone,email']);

        return ApiResponseClass::created(
            ['offer' => $this->serializeOffer($offer)],
            'Proposition d\'échange envoyée avec succès'
        );
    }

    public function updateStatus(UpdateTradeOfferStatusRequest $request, TradeOffer $tradeOffer): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if (!$this->canUpdateOfferStatus($user, $tradeOffer, (string) $request->validated('status'))) {
            return ApiResponseClass::forbidden('Vous n\'êtes pas autorisé à appliquer ce changement de statut.');
        }

        $validated = $request->validated();
        $nextStatus = (string) $validated['status'];
        $previousStatus = (string) $tradeOffer->status;
        $note = $validated['note'] ?? null;
        $metadata = $validated['metadata'] ?? null;

        if ($nextStatus === $previousStatus) {
            return ApiResponseClass::sendResponse(
                ['offer' => $this->serializeOffer($tradeOffer->load(['items', 'proposer:id,display_name,phone,email', 'recipient:id,display_name,phone,email']))],
                'Aucune modification de statut à appliquer'
            );
        }

        DB::transaction(function () use ($tradeOffer, $nextStatus, $previousStatus, $user, $note, $metadata) {
            $tradeOffer->status = $nextStatus;

            if ($nextStatus === TradeOffer::STATUS_ACCEPTED) {
                $tradeOffer->accepted_at = now();
            }
            if ($nextStatus === TradeOffer::STATUS_REJECTED) {
                $tradeOffer->rejected_at = now();
            }
            if ($nextStatus === TradeOffer::STATUS_CANCELLED) {
                $tradeOffer->cancelled_at = now();
            }
            if ($nextStatus === TradeOffer::STATUS_COMPLETED) {
                $tradeOffer->completed_at = now();
            }
            if ($nextStatus === TradeOffer::STATUS_DISPUTED) {
                $tradeOffer->disputed_at = now();
            }

            $tradeOffer->save();

            $this->recordStatusHistory(
                tradeOffer: $tradeOffer,
                fromStatus: $previousStatus,
                toStatus: $nextStatus,
                changedBy: (string) $user->id,
                note: $note,
                metadata: is_array($metadata) ? $metadata : null,
            );
        });

        $tradeOffer->load(['items', 'proposer:id,display_name,phone,email', 'recipient:id,display_name,phone,email']);

        return ApiResponseClass::sendResponse(
            ['offer' => $this->serializeOffer($tradeOffer)],
            'Statut de la proposition mis à jour avec succès'
        );
    }

    private function canUpdateOfferStatus(User $user, TradeOffer $offer, string $nextStatus): bool
    {
        $isSuperAdmin = (bool) ($user->role?->is_super_admin ?? false);
        $isProposer = (string) $offer->proposer_id === (string) $user->id;
        $isRecipient = (string) $offer->recipient_id === (string) $user->id;

        if ($isSuperAdmin) {
            return true;
        }

        if (in_array($nextStatus, [TradeOffer::STATUS_ACCEPTED, TradeOffer::STATUS_REJECTED], true)) {
            return $isRecipient;
        }

        if ($nextStatus === TradeOffer::STATUS_CANCELLED) {
            return $isRecipient || $isProposer;
        }

        return false;
    }

    private function recordStatusHistory(
        TradeOffer $tradeOffer,
        ?string $fromStatus,
        string $toStatus,
        string $changedBy,
        ?string $note,
        ?array $metadata,
    ): void {
        TradeOfferStatusHistory::query()->create([
            'trade_offer_id' => $tradeOffer->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $changedBy,
            'note' => $note,
            'metadata' => $metadata,
            'changed_at' => now(),
        ]);
    }

    private function serializeOffer(TradeOffer $offer): array
    {
        return [
            'id' => (string) $offer->id,
            'listing_id' => (string) $offer->listing_id,
            'proposer_id' => (string) $offer->proposer_id,
            'proposer_name' => $offer->proposer?->display_name,
            'recipient_id' => (string) $offer->recipient_id,
            'recipient_name' => $offer->recipient?->display_name,
            'offered_estimated_value' => (float) ($offer->offered_estimated_value ?? 0),
            'requested_estimated_value' => (float) ($offer->requested_estimated_value ?? 0),
            'cash_complement' => (float) ($offer->cash_complement ?? 0),
            'compatibility_score' => $offer->compatibility_score,
            'comment' => $offer->comment,
            'status' => $offer->status,
            'expires_at' => $offer->expires_at?->toIso8601String(),
            'accepted_at' => $offer->accepted_at?->toIso8601String(),
            'rejected_at' => $offer->rejected_at?->toIso8601String(),
            'cancelled_at' => $offer->cancelled_at?->toIso8601String(),
            'completed_at' => $offer->completed_at?->toIso8601String(),
            'disputed_at' => $offer->disputed_at?->toIso8601String(),
            'items' => $offer->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'listing_id' => $item->listing_id ? (string) $item->listing_id : null,
                'title' => $item->title,
                'category' => $item->category,
                'condition_label' => $item->condition_label,
                'estimated_value' => $item->estimated_value,
                'metadata' => $item->metadata,
            ])->values()->all(),
            'created_at' => $offer->created_at?->toIso8601String(),
            'updated_at' => $offer->updated_at?->toIso8601String(),
        ];
    }
}
