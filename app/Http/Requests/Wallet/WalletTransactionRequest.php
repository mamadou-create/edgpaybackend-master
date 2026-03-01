<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class WalletTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 🔐 à adapter si tu veux restreindre l'accès selon l'utilisateur
    }

    public function rules(): array
    {
        return [

            'wallet_id'   => ['required', 'exists:wallets,id'],
            'user_id' => ['required', 'exists:users,id'],
            'amount'      => ['required', 'integer', 'min:1'], // ✅ integer car pas de décimales en GNF
            'type'        => ['required', 'string', 'in:commission,transfer,topup,withdrawal'],
            'reference'   => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'metadata'    => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'wallet_id.required' => 'Le portefeuille est requis.',
            'wallet_id.exists'   => 'Le portefeuille est introuvable.',

            'user_id.required' => 'L’utilisateur est requis.',
            'user_id.exists'   => 'L’utilisateur est introuvable.',

            'amount.required' => 'Le montant est requis.',
            'amount.integer'  => 'Le montant doit être un entier (pas de virgules en GNF).',
            'amount.min'      => 'Le montant doit être supérieur à 0.',

            'type.required' => 'Le type de transaction est requis.',
            'type.in'       => 'Le type doit être : commission, transfer, topup ou withdrawal.',

            'reference.max'    => 'La référence ne peut pas dépasser 255 caractères.',
            'description.max'  => 'La description ne peut pas dépasser 500 caractères.',
        ];
    }
}
