<?php

namespace App\Http\Requests\Announcement;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'category' => ['required', 'string', 'max:80', Rule::in(Announcement::categories())],
            'message' => 'required|string',
            'media' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,mp4,mov,avi,webm,m4v',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/x-msvideo,video/webm',
                'max:102400',
            ],
            'target_roles' => 'nullable|array',
            'diffusion_duration_days' => 'nullable|integer|min:1|max:3650',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Le titre est requis',
            'category.required' => 'La catégorie est requise',
            'category.in' => 'Choisissez une catégorie valide dans la liste proposée',
            'message.required' => 'Le message est requis',
            'media.mimes' => 'Le média doit être une image JPG, PNG, WEBP ou une vidéo MP4, MOV, AVI, WEBM',
            'media.max' => 'Le média ne doit pas dépasser 100 Mo',
            'target_roles.array' => 'Les rôles cibles doivent être un tableau',
            'diffusion_duration_days.integer' => 'La durée de diffusion doit être un nombre entier de jours',
            'diffusion_duration_days.min' => 'La durée de diffusion doit être d au moins 1 jour',
            'diffusion_duration_days.max' => 'La durée de diffusion ne peut pas dépasser 3650 jours',
        ];
    }
}