<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class OperatorCache extends Model
{
    use TraitUuid;

    protected $table = 'operator_cache';

    protected $fillable = [
        'id',
        'provider',
        'operator_code',
        'operator_name',
        'country_code',
        'network',
        'supports_airtime',
        'supports_data',
        'min_amount',
        'max_amount',
        'raw_payload',
        'last_synced_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'supports_airtime' => 'boolean',
            'supports_data' => 'boolean',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
