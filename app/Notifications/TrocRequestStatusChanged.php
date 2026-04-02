<?php

namespace App\Notifications;

use App\Models\TrocRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class TrocRequestStatusChanged extends Notification
{
    use Queueable;

    public function __construct(private readonly TrocRequest $trocRequest)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        return new DatabaseMessage($this->payload());
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    private function payload(): array
    {
        return [
            'request_id' => $this->trocRequest->id,
            'status' => $this->trocRequest->status,
            'title' => $this->titleForStatus($this->trocRequest->status),
            'body' => $this->bodyForStatus(),
            'admin_notes' => $this->trocRequest->admin_notes,
            'source_label' => trim($this->trocRequest->source_model . ' ' . $this->trocRequest->source_storage),
            'target_label' => trim($this->trocRequest->target_model . ' ' . $this->trocRequest->target_storage),
            'processed_at' => optional($this->trocRequest->processed_at)?->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function titleForStatus(string $status): string
    {
        return match ($status) {
            TrocRequest::STATUS_APPROVED => 'Demande approuvée',
            TrocRequest::STATUS_REJECTED => 'Demande refusée',
            TrocRequest::STATUS_COMPLETED => 'Échange finalisé',
            default => 'Mise à jour troc',
        };
    }

    private function bodyForStatus(): string
    {
        $sourceLabel = trim($this->trocRequest->source_model . ' ' . $this->trocRequest->source_storage);
        $targetLabel = trim($this->trocRequest->target_model . ' ' . $this->trocRequest->target_storage);

        return match ($this->trocRequest->status) {
            TrocRequest::STATUS_APPROVED => "Votre reprise {$sourceLabel} vers {$targetLabel} a été approuvée par le super admin.",
            TrocRequest::STATUS_REJECTED => "Votre demande {$sourceLabel} a été refusée. Consultez le retour admin pour la suite.",
            TrocRequest::STATUS_COMPLETED => "Votre échange {$sourceLabel} vers {$targetLabel} est marqué comme finalisé.",
            default => 'Le statut de votre demande troc a changé.',
        };
    }
}