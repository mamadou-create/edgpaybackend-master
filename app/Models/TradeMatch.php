<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeMatch extends Model
{
    use TraitUuid;

    protected $fillable = [
        'listing_id',
        'candidate_listing_id',
        'compatibility_score',
        'score_breakdown',
        'computed_at',
    ];

    protected $casts = [
        'compatibility_score' => 'float',
        'score_breakdown' => 'array',
        'computed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(UsedItemListing::class, 'listing_id');
    }

    public function candidateListing(): BelongsTo
    {
        return $this->belongsTo(UsedItemListing::class, 'candidate_listing_id');
    }
}
