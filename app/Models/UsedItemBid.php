<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsedItemBid extends Model
{
    use TraitUuid;

    protected $fillable = [
        'listing_id',
        'bidder_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(UsedItemListing::class, 'listing_id');
    }

    public function bidder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bidder_id');
    }
}