<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeOfferStatusHistory extends Model
{
    use TraitUuid;

    protected $fillable = [
        'trade_offer_id',
        'from_status',
        'to_status',
        'changed_by',
        'note',
        'metadata',
        'changed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'changed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tradeOffer(): BelongsTo
    {
        return $this->belongsTo(TradeOffer::class, 'trade_offer_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
