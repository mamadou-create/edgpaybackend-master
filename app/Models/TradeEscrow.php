<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeEscrow extends Model
{
    use TraitUuid;

    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DISPUTED = 'disputed';

    protected $fillable = [
        'trade_offer_id',
        'payer_user_id',
        'payee_user_id',
        'payer_wallet_id',
        'payee_wallet_id',
        'amount',
        'status',
        'reason',
        'metadata',
        'blocked_at',
        'released_at',
        'cancelled_at',
        'disputed_at',
        'resolved_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'metadata' => 'array',
        'blocked_at' => 'datetime',
        'released_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'disputed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tradeOffer(): BelongsTo
    {
        return $this->belongsTo(TradeOffer::class, 'trade_offer_id');
    }

    public function payerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function payeeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_user_id');
    }
}
