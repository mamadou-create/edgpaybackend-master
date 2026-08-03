<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReloadlyUtilityTransactionHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $intent = $this->paymentIntent;

        return [
            'id' => $this->id,
            'biller' => $this->biller_name ?? "Biller #{$this->biller_id}",
            'amount' => (float) $this->amount,
            'currency' => $intent?->payment_currency ?? $intent?->currency,
            'debited_amount' => $intent?->converted_amount === null ? null : (float) $intent->converted_amount,
            'wallet_currency' => $intent?->wallet_currency ?? $intent?->currency,
            'conversion_rate' => $intent?->conversion_rate === null ? null : (float) $intent->conversion_rate,
            'conversion_effective_at' => $intent?->conversion_effective_at?->toIso8601String(),
            'conversion_applied' => (bool) ($intent?->conversion_applied ?? false),
            'status' => $this->api_status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}