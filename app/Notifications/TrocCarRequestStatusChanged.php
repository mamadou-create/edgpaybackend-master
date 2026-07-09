<?php

namespace App\Notifications;

use App\Models\TrocCarRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class TrocCarRequestStatusChanged extends Notification
{
    use Queueable;

    public function __construct(private readonly TrocCarRequest $trocRequest)
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
            'source_label' => trim(
                $this->trocRequest->source_brand . ' ' . $this->trocRequest->source_model . ' ' . $this->trocRequest->source_year
            ),
            'target_label' => trim(
                $this->trocRequest->target_brand . ' ' . $this->trocRequest->target_model . ' ' . $this->trocRequest->target_year
            ),
            'processed_at' => optional($this->trocRequest->processed_at)?->toIso8601String(),
            'created_at' => now()->toIso8601String(),
            'module' => 'troc_car',
        ];
    }

    private function titleForStatus(string $status): string
    {
        return match ($status) {
            TrocCarRequest::STATUS_APPROVED => 'Demande voiture approuvee',
            TrocCarRequest::STATUS_REJECTED => 'Demande voiture refusee',
            TrocCarRequest::STATUS_COMPLETED => 'Echange voiture finalise',
            default => 'Mise a jour troc voiture',
        };
    }

    private function bodyForStatus(): string
    {
        $sourceLabel = trim(
            $this->trocRequest->source_brand . ' ' . $this->trocRequest->source_model . ' ' . $this->trocRequest->source_year
        );
        $targetLabel = trim(
            $this->trocRequest->target_brand . ' ' . $this->trocRequest->target_model . ' ' . $this->trocRequest->target_year
        );

        return match ($this->trocRequest->status) {
            TrocCarRequest::STATUS_APPROVED => "Votre reprise {$sourceLabel} vers {$targetLabel} a ete approuvee par le super admin.",
            TrocCarRequest::STATUS_REJECTED => "Votre demande {$sourceLabel} a ete refusee. Consultez le retour admin pour la suite.",
            TrocCarRequest::STATUS_COMPLETED => "Votre echange {$sourceLabel} vers {$targetLabel} est marque comme finalise.",
            default => 'Le statut de votre demande troc voiture a change.',
        };
    }
}
