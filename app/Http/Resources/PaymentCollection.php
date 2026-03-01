<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PaymentCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'compteur_id' => $payment->compteur_id,
                    'phone' => $payment->phone,
                    'merchant_payment_reference' => $payment->merchant_payment_reference,
                    'transaction_id' => $payment->transaction_id,
                    'payer_identifier' => $payment->payer_identifier,
                    'payment_method' => $payment->payment_method,
                    'amount' => (float) $payment->amount,
                    'country_code' => $payment->country_code,
                    'currency_code' => $payment->currency_code,
                    'description' => $payment->description,
                    'status' => $payment->status,
                    'payment_type' => $payment->payment_type,
                    'external_reference' => $payment->external_reference,
                    'gateway_url' => $payment->gateway_url,
                    'raw_request' => $payment->raw_request,
                    'raw_response' => $payment->raw_response,
                    'metadata' => is_string($payment->metadata)
                        ? json_decode($payment->metadata, true)
                        : $payment->metadata,

                    'processed_at' => optional($payment->processed_at)->toISOString(),
                    'service_type' => $payment->service_type,
                    'dml_reference' => $payment->dml_reference,
                    'processing_attempts' => $payment->processing_attempts,
                    'created_at' => optional($payment->created_at)->toISOString(),
                    'updated_at' => optional($payment->updated_at)->toISOString(),
                    'user' => $payment->relationLoaded('user')
                        ? [
                            'id' => $payment->user->id,
                            'name' => $payment->user->display_name,
                            'email' => $payment->user->email,
                        ]
                        : null,
                ];
            }),
            'meta' => [
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
                'per_page' => $this->perPage(),
                'total' => $this->total(),
                'from' => $this->firstItem(),
                'to' => $this->lastItem(),
            ],
            'links' => [
                'first' => $this->url(1),
                'last' => $this->url($this->lastPage()),
                'prev' => $this->previousPageUrl(),
                'next' => $this->nextPageUrl(),
            ],
        ];
    }
}
