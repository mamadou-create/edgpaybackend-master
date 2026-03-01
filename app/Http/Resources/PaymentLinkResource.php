<?php
// app/Http/Resources/PaymentLinkResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentLinkResource extends JsonResource
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
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),

            // Références
            'reference' => $this->reference,
            'payment_link_reference' => $this->payment_link_reference,
            'external_link_id' => $this->external_link_id,

            // Informations principales
            'amount_to_pay' => (float) $this->amount_to_pay,
            'link_name' => $this->link_name,
            'phone_number' => $this->phone_number,
            'description' => $this->description,
            'country_code' => $this->country_code,
            'payment_link_usage_type' => $this->payment_link_usage_type,

            // Dates de validité
            'expires_at' => $this->expires_at?->toISOString(),
            'date_from' => $this->date_from?->toISOString(),
            'valid_until' => $this->valid_until?->toISOString(),

            // Champs personnalisés
            'custom_fields' => $this->custom_fields ?? [],

            // Statut et URL
            'status' => $this->status,
            'link_url' => $this->link_url,

            // Données brutes
            'raw_request' => $this->raw_request ?? [],
            'raw_response' => $this->raw_response ?? [],

            // Métadonnées
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),

            // Liens API
            'links' => [
                'self' => route('payment-links.show', $this->id),
                'status' => $this->external_link_id ? route('payment-links.status', $this->external_link_id) : null,
            ],
        ];
    }
}
