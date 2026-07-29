<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;

class ReloadlyTransaction extends Model
{
    use TraitUuid;

    protected $guarded = [];

    protected $casts = [
        'api_response' => 'array',
        'transaction_date' => 'datetime',
        'requested_amount' => 'decimal:4',
        'delivered_amount' => 'decimal:4',
        'fee' => 'decimal:5',
        'discount' => 'decimal:5',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}