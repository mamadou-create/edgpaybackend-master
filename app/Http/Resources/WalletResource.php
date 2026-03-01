<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
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
            'currency' => $this->currency,
            'cash_available' => $this->cash_available,
            'commission_available' => $this->commission_available,
            'commission_balance' => $this->commission_balance,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => new UserResource($this->whenLoaded('user')),
            'floats' => WalletFloatResource::collection($this->whenLoaded('floats')),
            'wallet_transactions' => WalletTransactionResource::collection($this->whenLoaded('wallet_transactions')),
        ];
    }
}
