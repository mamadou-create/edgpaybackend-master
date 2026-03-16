<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; 
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('id');
        
        return [
            'email' => 'sometimes|nullable|string|email|max:255|unique:users,email,' . $userId,
            'phone' => 'sometimes|string|max:20|unique:users,phone,' . $userId,
            'display_name' => 'sometimes|string|max:255',
            'default_conversational_agent' => 'sometimes|nullable|string|max:80',
            'role_id' => 'sometimes',
            'is_pro' => 'sometimes|boolean',
            'solde_portefeuille' => 'sometimes|numeric',
            'commission_portefeuille' => 'sometimes|numeric',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.email' => 'L\'adresse email doit être valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'default_conversational_agent.max' => 'La clé de l agent conversationnel est trop longue.',
            'role_id' => 'Le rôle est obligatoire.',
            'is_pro.boolean' => 'Le champ is_pro doit être vrai ou faux.',
            'solde_portefeuille.numeric' => 'Le solde du portefeuille doit être un nombre.',
            'commission_portefeuille.numeric' => 'La commission du portefeuille doit être un nombre.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        $response = [
            'success' => false,
            'message' => 'Erreur de validation',
            'errors' => $validator->errors()
        ];

        throw new HttpResponseException(
            response()->json($response, Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}