<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradeOffer extends Model
{
    use TraitUuid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ESCROW_BLOCKED = 'escrow_blocked';
    public const STATUS_ESCROW_RELEASED = 'escrow_released';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISPUTED = 'disputed';

    protected $fillable = [
        'listing_id',
        'proposer_id',
        'recipient_id',
        'offered_estimated_value',
        'requested_estimated_value',
        'cash_complement',
        'compatibility_score',
        'comment',
        'status',
        'expires_at',
        'accepted_at',
        'rejected_at',
        'cancelled_at',
        'completed_at',
        'disputed_at',
    ];

    protected $casts = [
        'offered_estimated_value' => 'float',
        'requested_estimated_value' => 'float',
        'cash_complement' => 'float',
        'compatibility_score' => 'float',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'disputed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_ACCEPTED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_ESCROW_BLOCKED,
            self::STATUS_ESCROW_RELEASED,
            self::STATUS_DELIVERED,
            self::STATUS_COMPLETED,
            self::STATUS_DISPUTED,
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(UsedItemListing::class, 'listing_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposer_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TradeOfferItem::class, 'trade_offer_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(TradeOfferStatusHistory::class, 'trade_offer_id')
            ->orderByDesc('changed_at');
    }

    public function escrow(): HasOne
    {
        return $this->hasOne(TradeEscrow::class, 'trade_offer_id');
    }
}
