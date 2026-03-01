<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $isRead = false;
        $readAt = null;
        $authorName = 'Unknown';

        if ($user) {
            $readRecord = $this->readers->first();

            if ($readRecord) {
                $isRead = true;

                // Convertir read_at en Carbon si c'est une string
                $readAt = $readRecord->pivot->read_at;

                if (!empty($readAt) && !($readAt instanceof \Carbon\Carbon)) {
                    $readAt = Carbon::parse($readAt);
                }
            }
        }

        if ($this->relationLoaded('author') && $this->author) {
            $authorName = $this->author->display_name
                ?? $this->author->name
                ?? 'Unknown';
        }

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'author_id' => (string) $this->author_id,
            'author_name' => $authorName,

            // created_at est déjà un Carbon => OK
            'created_at' => $this->created_at?->toIso8601String(),

            'is_read' => $isRead,

            // read_at converti correctement
            'read_at' => $readAt?->toIso8601String(),

            'target_roles' => $this->target_roles ?? [],
        ];
    }
}
