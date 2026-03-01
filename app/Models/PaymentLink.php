<?php

namespace App\Models;

use App\Enums\PaymentLinkStatus;
use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentLink extends Model
{
    use TraitUuid, SoftDeletes;

    protected $fillable = [
        'reference',
        'payment_link_reference',
        'transaction_id',
        'external_link_id',
        'amount_to_pay',
        'link_name',
        'phone_number',
        'description',
        'country_code',
        'payment_link_usage_type',
        'expires_at',
        'date_from',
        'valid_until',
        'custom_fields',
        'link_url',
        'status',
        'raw_request',
        'raw_response',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentLinkStatus::class,
            'amount_to_pay' => 'decimal:2',
            'expires_at' => 'datetime',
            'date_from' => 'datetime',
            'valid_until' => 'datetime',
            'custom_fields' => 'array',
            'raw_request' => 'array',
            'raw_response' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Relation avec l'utilisateur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vérifie si le lien est expiré
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Vérifie si le lien est actif
     */
    public function isActive(): bool
    {
        return $this->status === 'ACTIVE' && !$this->isExpired();
    }
}
