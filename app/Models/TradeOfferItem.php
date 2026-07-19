<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeOfferItem extends Model
{
    use TraitUuid;

    protected $fillable = [
        'trade_offer_id',
        'listing_id',
        'title',
        'category',
        'condition_label',
        'estimated_value',
        'metadata',
    ];

    protected $casts = [
        'estimated_value' => 'float',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tradeOffer(): BelongsTo
    {
        return $this->belongsTo(TradeOffer::class, 'trade_offer_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(UsedItemListing::class, 'listing_id');
    }
}
