<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Wallet extends Model
{
    use TraitUuid, SoftDeletes;


    protected $fillable = [
        'user_id',
        'currency',
        'cash_available',
        'commission_available',
        'commission_balance',
        'blocked_amount'
    ];

    protected $with = [];



    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function floats()
    {
        return $this->hasMany(WalletFloat::class);
    }

    public function wallet_transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function withdrawalRequests()
    {
        return $this->hasMany(WithdrawalRequest::class);
    }


    protected function casts(): array
    {
        return [
            'cash_available' => 'integer',
            'commission_available' => 'integer',
            'commission_balance' => 'integer',
            'blocked_amount' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    // Méthodes utilitaires
    public function getNetBalanceAttribute(): int
    {
        return $this->cash_available - $this->blocked_amount;
    }

    public function getTotalBalanceAttribute(): int
    {
        return $this->cash_available + $this->commission_available;
    }

    public function hasSufficientBalance(int $amount): bool
    {
        return $this->getNetBalanceAttribute() >= $amount;
    }

    public function canWithdraw(int $amount): bool
    {
        return $this->cash_available >= $amount && $this->hasSufficientBalance($amount);
    }
}
