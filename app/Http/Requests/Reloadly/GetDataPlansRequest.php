<?php

namespace App\Http\Requests\Reloadly;

use Illuminate\Foundation\Http\FormRequest;

class GetDataPlansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operator_id' => ['required', 'integer', 'min:1'],
            'recipient_phone' => ['sometimes', 'string', 'min:8', 'max:25'],
        ];
    }
}
