<?php

namespace App\Notifications;

use App\Events\ReloadlyOrderProcessed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReloadlyOrderStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ReloadlyOrderProcessed $event)
    {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (!empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isSuccess = $this->event->status === 'SUCCESS';

        $subject = $isSuccess
            ? 'Recharge effectuée avec succès'
            : 'Échec de la recharge';

        $line = $isSuccess
            ? 'Votre opération a été traitée avec succès.'
            : 'Votre opération de recharge a échoué.';

        $mail = (new MailMessage())
            ->subject($subject)
            ->line($line)
            ->line('Type: ' . $this->event->orderType)
            ->line('Référence paiement: ' . $this->event->paymentTransactionId);

        if (!$isSuccess && $this->event->errorMessage !== null) {
            $mail->line('Raison: ' . $this->event->errorMessage);
        }

        if ($isSuccess && $this->event->reloadlyTransactionId !== null) {
            $mail->line('Transaction fournisseur: ' . $this->event->reloadlyTransactionId);
        }

        return $mail;
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
        $isSuccess = $this->event->status === 'SUCCESS';

        return [
            'title' => $isSuccess ? 'Recharge réussie' : 'Recharge échouée',
            'body' => $isSuccess
                ? 'Votre recharge a été exécutée avec succès.'
                : 'Votre recharge n\'a pas pu être finalisée.',
            'order_type' => $this->event->orderType,
            'order_id' => $this->event->orderId,
            'status' => $this->event->status,
            'payment_transaction_id' => $this->event->paymentTransactionId,
            'reloadly_transaction_id' => $this->event->reloadlyTransactionId,
            'error_code' => $this->event->errorCode,
            'error_message' => $this->event->errorMessage,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
