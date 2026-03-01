<?php

namespace App\Http\Resources;

use App\Helpers\UploadHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DemandeProResource extends JsonResource
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
            'user_id' => $this->user_id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'entreprise' => $this->entreprise,
            'ville' => $this->ville,
            'quartier' => $this->quartier,
            'type_piece' => $this->type_piece,
            'numero_piece' => $this->numero_piece,
            // ✅ Si chemin existe → URL, sinon null
            'piece_image_path' => $this->piece_image_path
                ? UploadHelper::getUrl($this->piece_image_path)
                : null,
            'email' => $this->email,
            'adresse' => $this->adresse,
            'telephone' => $this->telephone,
            'cancellation_reason' => $this->cancellation_reason,
            'status' => $this->status,
            'date_demande' => $this->date_demande,
            'date_decision' => $this->date_decision,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'cancelled_at' => $this->cancelled_at,
            'deleted_at' => $this->deleted_at,
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
