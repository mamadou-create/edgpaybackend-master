<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Announcement extends Model
{
    use TraitUuid;

    protected $fillable = [
        'title',
        'message',
        'author_id',
        'target_roles',
    ];

    protected $casts = [
        'target_roles' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * L'auteur de l'annonce
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Utilisateurs qui ont lu l'annonce
     */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_reads')
            ->withPivot('read_at')
            ->withTimestamps();
    }

    /**
     * Vérifie si l'annonce est destinée à un rôle spécifique
     */
    public function isForRole(string $role): bool
    {
        // Si target_roles est vide, l'annonce est pour tous
        if (empty($this->target_roles)) {
            return true;
        }

        return in_array($role, $this->target_roles);
    }

    /**
     * Vérifie si un utilisateur a lu l'annonce
     */
    public function isReadByUser(string $userId): bool
    {
        return $this->readers()->where('user_id', $userId)->exists();
    }

    /**
     * Marquer comme lu par un utilisateur
     */
    public function markAsRead(string $userId): void
    {
        if (!$this->isReadByUser($userId)) {
            // Option 1: Utiliser create() qui gère l'ID automatiquement
            AnnouncementRead::create([
                'announcement_id' => $this->id,
                'user_id' => $userId,
                'read_at' => now(),
            ]);

            // Option 2: Utiliser sync() sans détacher
            // $this->readers()->syncWithoutDetaching([
            //     $userId => ['read_at' => now()]
            // ]);
        }
    }

    /**
     * Formater pour l'API (correspond à votre modèle Flutter)
     */
    public function toApiFormat(?string $userId = null): array
    {
        $isRead = $userId ? $this->isReadByUser($userId) : false;
        $readRecord = $userId ? $this->readers()->where('user_id', $userId)->first() : null;

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'author_id' => (string) $this->author_id,
            'author_name' => $this->author->name ?? 'Inconnu',
            'created_at' => $this->created_at->toIso8601String(),
            'is_read' => $isRead,
            'read_at' => $readRecord?->pivot?->read_at?->toIso8601String(),
            'target_roles' => $this->target_roles ?? [],
        ];
    }

    /**
     * Scope pour filtrer par rôle utilisateur
     */
    public function scopeForUserRole($query, string $role)
    {
        return $query->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhere('target_roles', '[]')
                ->orWhereJsonContains('target_roles', $role);
        });
    }

    /**
     * Scope pour les annonces non lues par un utilisateur
     */
    public function scopeUnreadByUser($query, string $userId)
    {
        return $query->whereDoesntHave('readers', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        });
    }
}
