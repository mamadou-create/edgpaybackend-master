<?php

namespace App\Http\Requests\DemandePro;

use Illuminate\Foundation\Http\FormRequest;

class DemandeProRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'entreprise' => ['nullable', 'string', 'max:150'],
            'ville' => ['required', 'string', 'max:100'],
            'quartier' => ['required', 'string', 'max:100'],
            'type_piece' => ['required', 'string', 'max:100'],
            'numero_piece' => ['required', 'string', 'max:100'],
            'piece_image_path' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
            'email' => ['required', 'email', 'max:150'],
            'adresse' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'regex:/^[+0-9 ]{6,15}$/'],
            'status' => ['nullable', 'in:en attente,accepté,refusé,annulé'],
            'date_demande' => ['nullable', 'date'],
            'date_decision' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'L\'utilisateur est requis.',
            'user_id.exists' => 'Utilisateur introuvable.',
            'nom.required' => 'Le nom est requis.',
            'prenom.required' => 'Le prénom est requis.',
            'ville.required' => 'La ville est requise.',
            'quartier.required' => 'Le quartier est requis.',
            'type_piece.required' => 'Le type de pièce est requis.',
            'numero_piece.required' => 'Le numéro de pièce est requis.',
            'email.required' => 'L\'adresse e-mail est requise.',
            'email.email' => 'Format d\'email invalide.',
            'adresse.required' => 'L\'adresse est requise.',
            'telephone.required' => 'Le numéro de téléphone est requis.',
            'telephone.regex' => 'Format de téléphone invalide.',
            'status.in' => 'Statut invalide.',
        ];
    }
}
