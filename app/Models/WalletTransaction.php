<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WalletTransaction extends Model
{
    use TraitUuid, SoftDeletes;


    protected $fillable = [
        'wallet_id',
        'user_id',
        'amount',
        'type',
        'reference',
        'description',
        'metadata',
    ];
    protected $with = [];


    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
