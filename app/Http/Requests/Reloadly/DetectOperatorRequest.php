<?php

namespace App\Http\Requests\Reloadly;

use Illuminate\Foundation\Http\FormRequest;

class DetectOperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:8', 'max:25'],
            'country_code' => ['sometimes', 'string', 'size:2'],
        ];
    }
}
