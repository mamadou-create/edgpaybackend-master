<?php

namespace App\Http\Resources;

use App\Data\ReloadlyBillerDTO;
use App\Data\UtilityPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReloadlyBillerResource extends JsonResource
{
    /** @var ReloadlyBillerDTO */
    public $resource;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'country_iso_code' => $this->resource->countryISOCode,
            'type' => $this->resource->type,
            'service_type' => $this->resource->serviceType,
            'local_amount_supported' => $this->resource->localAmountSupported,
            'local_transaction_currency_code' => $this->resource->localTransactionCurrencyCode,
            'international_transaction_currency_code' => $this->resource->internationalTransactionCurrencyCode,
            'denomination_type' => $this->resource->denominationType,
            'local_fixed_amounts' => array_map(
                static fn (UtilityPlan $plan): array => $plan->toArray(),
                $this->resource->localFixedAmounts,
            ),
            'min_local_transaction_amount' => $this->resource->minLocalTransactionAmount,
            'max_local_transaction_amount' => $this->resource->maxLocalTransactionAmount,
            'requires_invoice' => $this->resource->requiresInvoice,
        ];
    }
}