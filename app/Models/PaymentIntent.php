<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class PaymentIntent extends Model
{
    use TraitUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'converted_amount' => 'decimal:4',
            'conversion_rate' => 'decimal:8',
            'conversion_effective_at' => 'datetime',
            'conversion_applied' => 'boolean',
            'subscriber_account_number' => 'encrypted',
            'provider_response' => 'array',
        ];
    }
}