<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->phone,
            'display_name' => $this->display_name,
            'is_pro' => $this->is_pro,
            'solde_portefeuille' => (float) $this->solde_portefeuille,
            'commission_portefeuille' => (float) $this->commission_portefeuille,
            'status' => $this->status,
            'profile_photo_path' => $this->profile_photo_path,
            'two_factor_enabled' => $this->two_factor_enabled,
            'two_factor_secret' => $this->two_factor_secret,
            'two_factor_expires_at' => $this->two_factor_expires_at,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'assigned_user' => $this->assigned_user,
            'user_asseigned' => new UserResource($this->whenLoaded('user_assigned')),
             // 🧩 Rôle complet
             'role_name' => optional($this->role)->slug,
             'role' => new RoleResource($this->whenLoaded('role')),
             'wallet' => new WalletResource($this->whenLoaded('wallet')),
        ];
    }
}
