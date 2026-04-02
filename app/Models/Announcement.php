<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Announcement extends Model
{
    use TraitUuid;

    public const MODERATION_PENDING = 'pending';
    public const MODERATION_APPROVED = 'approved';
    public const MODERATION_REJECTED = 'rejected';

    public const CATEGORY_PROMOTION = 'Promotion';
    public const CATEGORY_PRODUCT = 'Produit';
    public const CATEGORY_SERVICE = 'Service';
    public const CATEGORY_EVENT = 'Événement';
    public const CATEGORY_JOB = 'Offre d emploi';
    public const CATEGORY_REAL_ESTATE = 'Immobilier';
    public const CATEGORY_TRANSPORT = 'Transport';
    public const CATEGORY_URGENT = 'Urgence';
    public const CATEGORY_OTHER = 'Autres';

    protected $fillable = [
        'title',
        'category',
        'message',
        'media_url',
        'media_type',
        'media_name',
        'moderation_status',
        'moderation_notes',
        'moderated_at',
        'moderated_by',
        'author_id',
        'target_roles',
        'publication_fee_amount',
        'diffusion_duration_days',
        'diffusion_starts_at',
        'diffusion_ends_at',
    ];

    protected $casts = [
        'target_roles' => 'array',
        'publication_fee_amount' => 'float',
        'moderated_at' => 'datetime',
        'diffusion_duration_days' => 'integer',
        'diffusion_starts_at' => 'datetime',
        'diffusion_ends_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public static function moderationStatuses(): array
    {
        return [
            self::MODERATION_PENDING,
            self::MODERATION_APPROVED,
            self::MODERATION_REJECTED,
        ];
    }

    public static function categories(): array
    {
        return [
            self::CATEGORY_PROMOTION,
            self::CATEGORY_PRODUCT,
            self::CATEGORY_SERVICE,
            self::CATEGORY_EVENT,
            self::CATEGORY_JOB,
            self::CATEGORY_REAL_ESTATE,
            self::CATEGORY_TRANSPORT,
            self::CATEGORY_URGENT,
            self::CATEGORY_OTHER,
        ];
    }

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

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_likes')
            ->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AnnouncementComment::class)
            ->latest('created_at');
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

    public function isLikedByUser(string $userId): bool
    {
        return $this->likes()->where('user_id', $userId)->exists();
    }

    public function isExpired(): bool
    {
        return $this->diffusion_ends_at !== null && $this->diffusion_ends_at->isPast();
    }

    public function isApproved(): bool
    {
        return ($this->moderation_status ?? self::MODERATION_APPROVED) === self::MODERATION_APPROVED;
    }

    public function isPendingModeration(): bool
    {
        return ($this->moderation_status ?? self::MODERATION_APPROVED) === self::MODERATION_PENDING;
    }

    public function isRejected(): bool
    {
        return ($this->moderation_status ?? self::MODERATION_APPROVED) === self::MODERATION_REJECTED;
    }

    public function isCurrentlyVisible(): bool
    {
        return !$this->isExpired() && $this->isApproved();
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
            'category' => $this->category ?? self::CATEGORY_OTHER,
            'message' => $this->message,
            'media_url' => $this->media_url,
            'media_type' => $this->media_type,
            'media_name' => $this->media_name,
            'moderation_status' => $this->moderation_status ?? self::MODERATION_APPROVED,
            'moderation_notes' => $this->moderation_notes,
            'moderated_at' => $this->moderated_at?->toIso8601String(),
            'moderated_by' => $this->moderated_by !== null ? (string) $this->moderated_by : null,
            'author_id' => (string) $this->author_id,
            'author_name' => $this->author->name ?? 'Inconnu',
            'created_at' => $this->created_at->toIso8601String(),
            'is_read' => $isRead,
            'read_at' => $readRecord?->pivot?->read_at?->toIso8601String(),
            'target_roles' => $this->target_roles ?? [],
            'publication_fee_amount' => (float) ($this->publication_fee_amount ?? 0),
            'diffusion_duration_days' => $this->diffusion_duration_days,
            'diffusion_starts_at' => $this->diffusion_starts_at?->toIso8601String(),
            'diffusion_ends_at' => $this->diffusion_ends_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),
            'likes_count' => (int) ($this->likes_count ?? $this->likes()->count()),
            'comments_count' => (int) ($this->comments_count ?? $this->comments()->count()),
            'is_liked_by_current_user' => $userId ? $this->isLikedByUser($userId) : false,
        ];
    }

    public function scopeCurrentlyVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $builder) {
            $builder->where(function (Builder $statusQuery) {
                $statusQuery->whereNull('moderation_status')
                    ->orWhere('moderation_status', self::MODERATION_APPROVED);
            })->where(function (Builder $visibilityQuery) {
                $visibilityQuery->whereNull('diffusion_ends_at')
                ->orWhere('diffusion_ends_at', '>', now());
            });
        });
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
