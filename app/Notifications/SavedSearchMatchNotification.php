<?php

namespace App\Notifications;

use App\Models\SavedSearch;
use App\Models\UsedItemListing;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class SavedSearchMatchNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly SavedSearch $savedSearch,
        private readonly UsedItemListing $listing,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage($this->payload());
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    private function payload(): array
    {
        return [
            'saved_search_id' => (string) $this->savedSearch->id,
            'listing_id' => (string) $this->listing->id,
            'title' => 'Nouvelle correspondance',
            'body' => 'Une nouvelle annonce correspond exactement à votre recherche.',
            'listing_title' => $this->listing->title,
            'listing_category' => $this->listing->category,
            'transaction_type' => $this->listing->transaction_type,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
