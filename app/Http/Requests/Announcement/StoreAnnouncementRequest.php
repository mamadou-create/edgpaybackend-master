<?php

namespace App\Http\Requests\Announcement;

use App\Enums\RoleEnum;
use Illuminate\Foundation\Http\FormRequest;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
      return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'target_roles' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Le titre est requis',
            'message.required' => 'Le message est requis',
            'target_roles.array' => 'Les rôles cibles doivent être un tableau',
        ];
    }
}