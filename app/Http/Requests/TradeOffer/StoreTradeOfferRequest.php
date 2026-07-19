<?php

namespace App\Http\Requests\TradeOffer;

use Illuminate\Foundation\Http\FormRequest;

class StoreTradeOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.listing_id' => ['nullable', 'uuid', 'exists:used_item_listings,id'],
            'items.*.title' => ['required', 'string', 'max:160'],
            'items.*.category' => ['nullable', 'string', 'max:80'],
            'items.*.condition_label' => ['nullable', 'string', 'max:80'],
            'items.*.estimated_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.metadata' => ['nullable', 'array'],
            'requested_estimated_value' => ['nullable', 'numeric', 'min:0'],
            'cash_complement' => ['nullable', 'numeric', 'min:0'],
            'compatibility_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ];
    }
}
