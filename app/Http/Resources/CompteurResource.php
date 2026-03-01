<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompteurResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => new UserResource($this->whenLoaded('client')),
            'email' => $this->email,
            'phone' => $this->phone,
            'display_name' => $this->display_name,
            'compteur' => $this->compteur,
            'type_compteur' => $this->type_compteur,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
