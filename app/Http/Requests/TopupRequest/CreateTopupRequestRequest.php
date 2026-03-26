<?php

namespace App\Http\Requests\TopupRequest;

use App\Helpers\HelperStatus;
use Illuminate\Foundation\Http\FormRequest;

class CreateTopupRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pro_id' => ['required', 'exists:users,id'],
            'amount' => ['required','integer','min:1000'],
            'balance_target' => ['nullable', 'in:wallet_principal,avoir_creance'],
            // 'kind' => ['required','in:' . implode(',', HelperStatus::getTopupSource())],
            // 'idempotency_key' => ['string', 'max:255','unique:topup_requests,idempotency_key'],
            'note' => ['nullable','string','max:1000'],
            'status' => ['nullable', 'in:' . implode(',', HelperStatus::getTopupRequestsStatuses())],
            'date_demande' => ['nullable', 'date'],
            'date_decision' => ['nullable', 'date'],


        ];
    }

    public function messages(): array
    {
        return [
            'pro_id.required' => 'Le pro est requis.',
            'pro_id.exists' => 'Pro introuvable.',
            'amount.required' => 'Le montant est obligatoire',
            'amount.integer' => 'Le montant doit être un nombre entier',
            'amount.min' => 'Le montant minimum est de 1000',
            'balance_target.in' => 'La destination du solde est invalide.',
            // 'kind.required' => 'Le type de recharge est obligatoire',
            // 'kind.in' => 'Le type de recharge doit être CASH, EDG ou PARTNER',
            'idempotency_key.required' => 'La clé d\'idempotence est obligatoire',
            'idempotency_key.unique' => 'Une demande avec cette clé existe déjà',
            'status.in' => 'Statut invalide.',
            'note.max' => 'La note ne peut pas dépasser 1000 caractères'
        ];
    }
}
