<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentTransaction extends Model
{
    use TraitUuid, SoftDeletes;

    protected $fillable = [
        'id',
        'transaction_id',
        'user_id',
        'provider',
        'channel',
        'payment_reference',
        'merchant_reference',
        'provider_payment_id',
        'msisdn',
        'amount',
        'currency',
        'status',
        'confirmation_status',
        'idempotency_key',
        'correlation_id',
        'webhook_verified',
        'webhook_verified_at',
        'paid_at',
        'expires_at',
        'raw_request',
        'raw_response',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'webhook_verified' => 'boolean',
            'webhook_verified_at' => 'datetime',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'raw_request' => 'array',
            'raw_response' => 'array',
            'metadata' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function airtimeOrders(): HasMany
    {
        return $this->hasMany(AirtimeOrder::class);
    }

    public function dataOrders(): HasMany
    {
        return $this->hasMany(DataOrder::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }
}
