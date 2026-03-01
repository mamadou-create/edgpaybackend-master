<?php

namespace App\Http\Resources;

use App\Helpers\HelperStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TopupRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pro_id' => $this->pro_id,
            // 'pro_name' => $this->pro->displayName ?? 'Utilisateur inconnu',
            'pro_email' => $this->pro->email ?? null,
            'decided_by' => $this->decided_by,
            // 'decider_name' => $this->decider->displayName ?? null,
            'amount' => $this->amount,
            // 'kind' => $this->getKindLabel(),
            // 'kind_label' => $this->getKindLabel(),
            'status' => $this->getStatusLabel(),
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'idempotency_key' => $this->idempotency_key,
            'note' => $this->note,
            'cancellation_reason' => $this->cancellation_reason,
            'date_demande' => $this->date_demande,
            'date_decision' => $this->date_decision,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'cancelled_at' => $this->cancelled_at,
            'deleted_at' => $this->deleted_at,
            'pro' => new UserResource($this->whenLoaded('pro')),
            'decider' => new UserResource($this->whenLoaded('decider')),
        ];
    }

    private function getKindLabel(): string
    {
        return match ($this->kind) {
            'CASH' => 'Espèce',
            'EDG' => 'EDG',
            'GSS' => 'GSS',
            'PARTNER' => 'Partenaire',
            default => $this->kind
        };
    }

    private function getStatusLabel(): string
    {
        return match ($this->status) {
            HelperStatus::PENDING   => 'En attente',
            HelperStatus::APPROVED  => 'Approuvée',
            HelperStatus::REJECTED  => 'Rejetée',
            HelperStatus::CANCELLED => 'Annulée',
            default                 => $this->status ?? 'Inconnu',
        };
    }


    private function getStatusColor(): string
    {
        return match ($this->status) {
            HelperStatus::PENDING => 'warning',
            HelperStatus::APPROVED => 'success',
            HelperStatus::REJECTED => 'danger',
            HelperStatus::CANCELLED => 'secondary',
            default => 'info'
        };
    }
}
