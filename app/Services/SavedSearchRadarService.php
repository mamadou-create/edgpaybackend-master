<?php

namespace App\Services;

use App\Models\SavedSearch;
use App\Models\UsedItemListing;
use App\Models\User;
use App\Notifications\SavedSearchMatchNotification;

class SavedSearchRadarService
{
    public function scanForUser(User $user, bool $notify = true): array
    {
        $results = [];

        $savedSearches = SavedSearch::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        foreach ($savedSearches as $savedSearch) {
            $filters = is_array($savedSearch->filters) ? $savedSearch->filters : [];

            $query = UsedItemListing::query()
                ->where('moderation_status', UsedItemListing::MODERATION_APPROVED)
                ->where('status', UsedItemListing::STATUS_ACTIVE)
                ->where(function ($builder) {
                    $builder->whereNull('publication_ends_at')
                        ->orWhere('publication_ends_at', '>', now());
                })
                ->orderByDesc('created_at');

            if (!empty($filters['transaction_type'])) {
                $query->where('transaction_type', $filters['transaction_type']);
            }
            if (!empty($filters['category'])) {
                $query->where('category', $filters['category']);
            }
            if (!empty($filters['city'])) {
                $query->where('city', 'like', '%' . $filters['city'] . '%');
            }
            if (!empty($filters['item_condition'])) {
                $query->where('item_condition', 'like', '%' . $filters['item_condition'] . '%');
            }
            if (!empty($filters['wanted_object'])) {
                $wantedObject = (string) $filters['wanted_object'];
                $query->where(function ($builder) use ($wantedObject) {
                    $builder->where('wanted_object', 'like', '%' . $wantedObject . '%')
                        ->orWhereJsonContains('wanted_objects', $wantedObject);
                });
            }
            if (isset($filters['min_price'])) {
                $query->where('price', '>=', (float) $filters['min_price']);
            }
            if (isset($filters['max_price'])) {
                $query->where('price', '<=', (float) $filters['max_price']);
            }

            if ($savedSearch->last_notified_at !== null) {
                $query->where('created_at', '>', $savedSearch->last_notified_at);
            }

            $matches = $query->limit(25)->get();
            if ($matches->isNotEmpty() && $notify) {
                foreach ($matches as $listing) {
                    $user->notify(new SavedSearchMatchNotification($savedSearch, $listing));
                }

                $savedSearch->last_notified_at = now();
                $savedSearch->save();
            }

            $results[] = [
                'saved_search_id' => (string) $savedSearch->id,
                'name' => $savedSearch->name,
                'matches_count' => $matches->count(),
                'matches' => $matches->map(fn ($listing) => [
                    'id' => (string) $listing->id,
                    'title' => $listing->title,
                    'category' => $listing->category,
                    'transaction_type' => $listing->transaction_type,
                    'created_at' => $listing->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return $results;
    }
}
