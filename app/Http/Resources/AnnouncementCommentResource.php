<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $author = $this->relationLoaded('author') ? $this->author : null;

        return [
            'id' => (string) $this->id,
            'announcement_id' => (string) $this->announcement_id,
            'author_id' => (string) $this->author_id,
            'author_name' => $author?->display_name
                ?? $author?->name
                ?? 'Inconnu',
            'author_phone' => $author?->phone,
            'content' => (string) $this->content,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}