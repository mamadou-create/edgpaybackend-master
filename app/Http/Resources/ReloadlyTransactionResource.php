<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ReloadlyTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                    => $this->id,
            'reloadly_transaction_id'=> $this->reloadly_transaction_id,
            'operator'              => $this->operator_name,
            'phone'                 => $this->recipient_phone,
            'requested_amount'      => (float) $this->requested_amount,
            'requested_currency'    => $this->requested_currency,
            'delivered_amount'      => (float) $this->delivered_amount,
            'delivered_currency'    => $this->delivered_currency,
            'status'                => $this->api_status,
            'error'                 => $this->error_message,
            'date'                  => $this->transaction_date?->toIso8601String(),
            'created_at'            => $this->created_at->toIso8601String(),
           'user' => $this->whenLoaded('user', function () {
                return [
                    'id'           => $this->user->id,
                    'display_name' => $this->user->display_name,
                    'email'        => $this->user->email,
                    'phone'        => $this->user->phone,
                ];
            }),
        ];
    }
}