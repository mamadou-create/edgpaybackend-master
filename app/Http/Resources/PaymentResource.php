<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'compteur_id' => $this->compteur_id,
            'phone' => $this->phone,
            'merchant_payment_reference' => $this->merchant_payment_reference,
            'transaction_id' => $this->transaction_id,
            'payer_identifier' => $this->payer_identifier,
            'payment_method' => $this->payment_method,
            'amount' => (float) $this->amount,
            'country_code' => $this->country_code,
            'currency_code' => $this->currency_code,
            'description' => $this->description,
            'status' => $this->status,
            'payment_type' => $this->payment_type,
            'external_reference' => $this->external_reference,
            'gateway_url' => $this->gateway_url,
            'raw_request' => $this->raw_request,
            'raw_response' => $this->raw_response,
            'metadata' => $this->metadata ? json_decode($this->metadata, true) : null,
            'processed_at' => $this->processed_at?->toISOString(),
            'service_type' => $this->service_type,
            'dml_reference' => $this->dml_reference,
            'processing_attempts' => $this->processing_attempts,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}