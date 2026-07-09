<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class CreateAirtimePurchaseIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_provider' => ['required', 'string', 'max:50'],
            'payment_channel' => ['sometimes', 'string', 'max:50'],
            'payer_msisdn' => ['sometimes', 'nullable', 'string', 'min:8', 'max:25'],
            'recipient_phone' => ['required', 'string', 'min:8', 'max:25'],
            'recipient_country_code' => ['sometimes', 'string', 'size:2'],
            'operator_id' => ['required', 'integer', 'min:1'],
            'operator_name' => ['sometimes', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'expires_in_minutes' => ['sometimes', 'integer', 'min:5', 'max:60'],
        ];
    }
}
