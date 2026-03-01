<?php

namespace App\Http\Requests\TopupRequest;

use App\Helpers\HelperStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
        // return auth()->check() && auth()->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            // 'pro_id' => ['required', 'exists:users,id'],
            'status' => [
                'required',
                Rule::in(HelperStatus::getTopupRequestsStatuses()),
            ],

            'cancellation_reason' => [
                'required_if:status,REJECTED,CANCELLED',
                'nullable',
                'string',
                'max:500'
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Le statut est obligatoire',
            'status.in' => 'Le statut doit être PENDING, APPROVED, REJECTED ou CANCELLED',
            'cancellation_reason.required_if' => 'La raison est obligatoire pour le rejet ou l\'annulation',
            'cancellation_reason.max' => 'La raison ne peut pas dépasser 500 caractères'
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->status === HelperStatus::APPROVED && $this->reason) {
                $validator->errors()->add(
                    'reason',
                    'La raison ne peut pas être fournie pour une approbation'
                );
            }
        });
    }
}
