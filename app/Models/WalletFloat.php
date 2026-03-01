<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WalletFloat extends Model
{
    use TraitUuid, SoftDeletes;


    protected $fillable = [
        'wallet_id',
        'balance',
        'commission',
        'provider',
        'rate'
    ];


    protected $with = [];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'commission' => 'integer',
            'rate' => 'float',
            'deleted_at' => 'datetime',
        ];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
