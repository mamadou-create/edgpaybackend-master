<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class ReloadlyUtilityTransaction extends Model
{
    use TraitUuid;

    protected $guarded = [];

    protected $casts = [
        'api_response' => 'array',
        'transaction_date' => 'datetime',
        'amount' => 'decimal:4',
        'use_local_amount' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paymentIntent()
    {
        return $this->hasOne(PaymentIntent::class, 'provider_reference', 'reference_id');
    }
}