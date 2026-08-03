<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class ReloadlyGiftcardTransaction extends Model
{
    use TraitUuid;

    protected $guarded = [];

    protected $casts = [
        'api_response' => 'array',
        'redeem_codes' => 'array',
        'transaction_date' => 'datetime',
        'unit_price' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'base_amount' => 'decimal:4',
        'commission_amount' => 'decimal:4',
        'wallet_amount' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}