<?php

namespace App\Http\Requests\Compteur;

use Illuminate\Foundation\Http\FormRequest;

class CompteurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => 'required|uuid|exists:users,id',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'display_name' => 'required|string|max:100',
            'compteur' => 'required|string|max:50|unique:compteurs,compteur,' . $this->route('id'),
            'type_compteur' => 'required|in:prepaid,postpayment',
        ];
    }
}
