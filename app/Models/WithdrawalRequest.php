<?php

// app/Models/WithdrawalRequest.php
namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    use HasFactory;

     use TraitUuid;

    protected $fillable = [
        'id',
        'wallet_id',
        'user_id',
        'amount',
        'provider',
        'status',
        'description',
        'metadata',
        'processed_by',
        'processed_at',
        'processing_notes',
    ];

    protected $casts = [
        'metadata' => 'array',
        'amount' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}