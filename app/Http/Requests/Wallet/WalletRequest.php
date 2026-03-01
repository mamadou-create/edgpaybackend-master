<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class WalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // tu peux gérer l'auth si nécessaire
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required'],
            'currency' => ['required', 'string', 'max:10'], // ex: GNF, USD, EUR
            'cash_available' => ['nullable', 'integer', 'min:0'],
            'commission_available' => ['nullable', 'integer', 'min:0'],
            'commission_balance' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'L\'utilisateur est requis.',
            'user_id.exists' => 'Utilisateur introuvable.',
            'currency.required' => 'La devise est requise.',
            'currency.max' => 'La devise ne peut pas dépasser 10 caractères.',
            'cash_available.integer' => 'Le solde doit être un entier (pas de virgule).',
            'commission_available.integer' => 'La commission disponible doit être un entier.',
            'commission_balance.integer' => 'Le solde de commission doit être un entier.',
        ];
    }
}
