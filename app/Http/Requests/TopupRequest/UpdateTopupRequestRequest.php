<?php

namespace App\Http\Requests\TopupRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTopupRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
         return true;
        // $topupRequest = $this->route('topupRequest');
        // return auth()->check() && 
        //        (auth()->user()->is_pro && $topupRequest->pro_id === auth()->id()) || 
        //        auth()->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'amount' => [
                'sometimes',
                'integer',
                'min:1000'
            ],
            'note' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000'
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant minimum est de 1000',
            'note.max' => 'La note ne peut pas dépasser 1000 caractères'
        ];
    }
}