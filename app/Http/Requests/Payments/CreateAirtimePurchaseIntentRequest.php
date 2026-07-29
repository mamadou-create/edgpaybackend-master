<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Classes\ApiResponseClass;

class CreateAirtimePurchaseIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency' => strtoupper((string) ($this->input('currency') ?: 'GNF')),
        ]);
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
            'currency' => ['required', 'string', 'size:3', 'in:GNF'],
            'expires_in_minutes' => ['sometimes', 'integer', 'min:5', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'currency.in' => 'La devise de recharge airtime doit être le GNF.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponseClass::validationError($validator->errors(), 'Erreur de validation')
        );
    }
}
