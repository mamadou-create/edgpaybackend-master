<?php

namespace App\Models;

use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    use TraitUuid;

    protected $fillable = [
        'id',
        'provider',
        'event_type',
        'event_id',
        'payment_transaction_id',
        'signature_header',
        'signature_valid',
        'correlation_id',
        'request_headers',
        'payload',
        'received_at',
        'processed_at',
        'status',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'request_headers' => 'array',
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class);
    }
}
