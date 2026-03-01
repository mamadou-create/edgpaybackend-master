<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\User;
use App\Traits\TraitUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use TraitUuid, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'compteur_id',
        'phone',
        'merchant_payment_reference',
        'transaction_id',
        'payer_identifier',
        'payment_method',
        'amount',
        'country_code',
        'currency_code',
        'description',
        'status',
        'payment_type',
        'external_reference',
        'gateway_url',
        'raw_request',
        'raw_response',
        'metadata',
        'processed_at',
        'service_type',
        'dml_reference',
        'processing_attempts',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_type' => PaymentType::class,
            'amount' => 'decimal:2',
            'raw_request' => 'array',
            'raw_response' => 'array',
            'metadata' => 'array',
            'processed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Incrémente les tentatives de traitement
     */
    public function incrementAttempts(): void
    {
        $this->increment('processing_attempts');
    }

    /**
     * Vérifie si le paiement a été traité par DML
     */
    public function isProcessed(): bool
    {
        return !is_null($this->processed_at);
    }

    /**
     * Vérifie si le paiement est éligible au traitement DML
     */
    public function isEligibleForDmlProcessing(): bool
    {
        return $this->status === PaymentStatus::SUCCESS &&
            !$this->isProcessed() &&
            $this->processing_attempts < 3 &&
            $this->isElectricityPayment();
    }

    /**
     * Vérifie si c'est un paiement d'électricité
     */
    public function isElectricityPayment(): bool
    {
        if (!$this->description) {
            return false;
        }

        $lowerDescription = strtolower($this->description);
        $isElectricity = str_contains($lowerDescription, 'électricité') ||
            str_contains($lowerDescription, 'edg') ||
            str_contains($lowerDescription, 'electricite');

        // Vérifier aussi dans les métadonnées
        if (!$isElectricity && $this->metadata) {
            $isElectricity = isset($this->metadata['electricity_payment']) &&
                $this->metadata['electricity_payment'] === true;
        }

        return $isElectricity;
    }

    /**
     * Marque le paiement comme traité
     */
    public function markAsProcessed(string $dmlReference = '', string $serviceType = ''): void
    {
        $this->update([
            'processed_at' => now(),
            'dml_reference' => $dmlReference,
            'service_type' => $serviceType,
            'processing_attempts' => $this->processing_attempts + 1
        ]);
    }

    /**
     * Récupère les métadonnées DML
     */
    public function getDmlMetadata(): array
    {
        return $this->metadata['dml'] ?? [];
    }

    /**
     * Définit les métadonnées DML
     */
    public function setDmlMetadata(array $metadata): void
    {
        $currentMetadata = $this->metadata ?? [];
        $currentMetadata['dml'] = $metadata;

        $this->update(['metadata' => $currentMetadata]);
    }

    /**
     * Scope pour les paiements éligibles au traitement DML
     */
    public function scopeEligibleForDml($query)
    {
        return $query->where('status', PaymentStatus::SUCCESS)
            ->whereNull('processed_at')
            ->where('processing_attempts', '<', 3)
            ->where(function ($q) {
                $q->where('description', 'like', '%électricité%')
                    ->orWhere('description', 'like', '%edg%')
                    ->orWhereJsonContains('metadata->electricity_payment', true);
            });
    }

    /**
     * Scope pour les paiements non traités
     */
    public function scopeNotProcessed($query)
    {
        return $query->whereNull('processed_at');
    }

    /**
     * Scope pour les paiements avec un type de service spécifique
     */
    public function scopeWithServiceType($query, string $serviceType)
    {
        return $query->where('service_type', $serviceType);
    }
}
