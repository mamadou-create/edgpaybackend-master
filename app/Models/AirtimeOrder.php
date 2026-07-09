<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AirtimeOrder extends Model
{
    use TraitUuid, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'transaction_id',
        'payment_transaction_id',
        'operator_id',
        'operator_name',
        'recipient_msisdn',
        'country_code',
        'amount',
        'local_amount',
        'local_currency',
        'reloadly_transaction_id',
        'status',
        'error_code',
        'error_message',
        'commission_amount',
        'is_promotion_applied',
        'delivered_at',
        'correlation_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'local_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'is_promotion_applied' => 'boolean',
            'delivered_at' => 'datetime',
            'metadata' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
