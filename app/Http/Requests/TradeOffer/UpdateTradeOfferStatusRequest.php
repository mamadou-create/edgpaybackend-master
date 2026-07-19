<?php

namespace App\Http\Requests\TradeOffer;

use App\Models\TradeOffer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTradeOfferStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(TradeOffer::statuses())],
            'note' => ['nullable', 'string', 'max:1500'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
