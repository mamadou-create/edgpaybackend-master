<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawalRequestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'wallet_id' => $this->wallet_id,
            'user_id' => $this->user_id,
            'amount' => $this->amount,
            'provider' => $this->provider,
            'status' => $this->status,
            'description' => $this->description,
            'metadata' => $this->metadata,
            
            // Informations de traitement
            'processed_by' => $this->processed_by,
            'processed_at' => $this->processed_at,
            'processing_notes' => $this->processing_notes,
            
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Relations
            'user' => new UserResource($this->whenLoaded('user')),
            'wallet' => new WalletResource($this->whenLoaded('wallet')),
        ];
    }
}