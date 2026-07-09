<?php

namespace App\Http\Requests\Reloadly;

use Illuminate\Foundation\Http\FormRequest;

class TopupAirtimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operator_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:1'],
            'use_local_amount' => ['required', 'boolean'],
            'custom_identifier' => ['required', 'string', 'max:120'],
            'recipient_phone' => ['required', 'string', 'min:8', 'max:25'],
            'recipient_country_code' => ['sometimes', 'string', 'size:2'],
            'sender_phone' => ['sometimes', 'string', 'min:8', 'max:25'],
            'sender_country_code' => ['sometimes', 'string', 'size:2'],
        ];
    }
}
