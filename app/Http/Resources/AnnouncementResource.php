<?php

namespace App\Http\Resources;

use App\Models\Announcement;
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

        $authorPhone = $this->relationLoaded('author') && $this->author
            ? $this->author->phone
            : null;
        $authorEmail = $this->relationLoaded('author') && $this->author
            ? $this->author->email
            : null;
        $authorWhatsappPhone = $this->relationLoaded('author') && $this->author
            ? ($this->author->whatsapp_phone ?? $this->author->phone)
            : null;
        $isLikedByCurrentUser = false;

        if ($user) {
            $isLikedByCurrentUser = $this->relationLoaded('likes')
                ? $this->likes->isNotEmpty()
                : $this->likes()->where('user_id', $user->id)->exists();
        }

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'category' => $this->category ?? Announcement::CATEGORY_OTHER,
            'message' => $this->message,
            'media_url' => $this->media_url,
            'media_type' => $this->media_type,
            'media_name' => $this->media_name,
            'moderation_status' => $this->moderation_status ?? Announcement::MODERATION_APPROVED,
            'moderation_notes' => $this->moderation_notes,
            'moderated_at' => $this->moderated_at?->toIso8601String(),
            'moderated_by' => $this->moderated_by !== null ? (string) $this->moderated_by : null,
            'author_id' => (string) $this->author_id,
            'author_name' => $authorName,
            'author_phone' => $authorPhone,
            'author_email' => $authorEmail,
            'author_whatsapp_phone' => $authorWhatsappPhone,
            'publication_fee_amount' => (float) ($this->publication_fee_amount ?? 0),
            'diffusion_duration_days' => $this->diffusion_duration_days,
            'diffusion_starts_at' => $this->diffusion_starts_at?->toIso8601String(),
            'diffusion_ends_at' => $this->diffusion_ends_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'unique_view_count' => (int) ($this->readers_count ?? 0),
            'likes_count' => (int) ($this->likes_count ?? $this->likes()->count()),
            'comments_count' => (int) ($this->comments_count ?? $this->comments()->count()),
            'is_liked_by_current_user' => $isLikedByCurrentUser,

            // created_at est déjà un Carbon => OK
            'created_at' => $this->created_at?->toIso8601String(),

            'is_read' => $isRead,

            // read_at converti correctement
            'read_at' => $readAt?->toIso8601String(),

            'target_roles' => $this->target_roles ?? [],
            'comments' => AnnouncementCommentResource::collection($this->whenLoaded('comments')),
        ];
    }
}
