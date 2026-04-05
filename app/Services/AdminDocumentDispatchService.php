<?php

namespace App\Services;

use App\Mail\AdminDocumentDispatchMail;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdminDocumentDispatchService
{
    public function __construct(
        private readonly WhatsAppCloudApiService $whatsApp,
    ) {}

    public function send(User $sender, array $payload): array
    {
        $channel = (string) ($payload['channel'] ?? '');

        return match ($channel) {
            'email' => $this->sendByEmail($sender, $payload),
            'whatsapp' => $this->sendByWhatsApp($sender, $payload),
            default => [
                'success' => false,
                'message' => 'Canal d\'envoi non supporté.',
                'status' => 422,
            ],
        };
    }

    private function sendByEmail(User $sender, array $payload): array
    {
        $recipientEmail = trim((string) ($payload['recipient_email'] ?? ''));
        $documentTitle = trim((string) ($payload['document_title'] ?? 'Document'));
        $documentNumber = trim((string) ($payload['document_number'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));

        try {
            @set_time_limit(120);

            Mail::to($recipientEmail)->send(
                new AdminDocumentDispatchMail(
                    sender: $sender,
                    documentTitle: $documentTitle,
                    documentNumber: $documentNumber,
                    messageText: $message,
                    recipientName: $this->cleanNullableString($payload['recipient_name'] ?? null),
                    pdfBytes: base64_decode((string) $payload['pdf_b64'], true) ?: '',
                    pdfName: (string) $payload['pdf_name'],
                    attachmentBytes: $this->decodeNullableBase64($payload['attachment_b64'] ?? null),
                    attachmentName: $this->cleanNullableString($payload['attachment_name'] ?? null),
                    attachmentMime: $this->cleanNullableString($payload['attachment_mime'] ?? null),
                )
            );

            Log::info('admin_document_dispatch.email_sent', [
                'document_number' => $documentNumber,
                'recipient' => $recipientEmail,
                'sender_id' => $sender->id,
            ]);

            return [
                'success' => true,
                'channel' => 'email',
                'message' => 'Email envoyé avec succès.',
                'recipient' => $recipientEmail,
            ];
        } catch (Throwable $exception) {
            Log::error('admin_document_dispatch.email_failed', [
                'document_number' => $documentNumber,
                'recipient' => $recipientEmail,
                'sender_id' => $sender->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'channel' => 'email',
                'message' => 'Échec de l\'envoi email. Vérifiez la configuration mail ou réessayez.',
                'errors' => [
                    'email' => [$exception->getMessage()],
                ],
                'status' => 422,
            ];
        }
    }

    private function sendByWhatsApp(User $sender, array $payload): array
    {
        $recipientPhone = trim((string) ($payload['recipient_phone'] ?? ''));
        $documentTitle = trim((string) ($payload['document_title'] ?? 'Document'));
        $documentNumber = trim((string) ($payload['document_number'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));
        $body = $message !== ''
            ? $message
            : sprintf('Bonjour, veuillez trouver ci-joint %s %s.', mb_strtolower($documentTitle), $documentNumber);

        try {
            @set_time_limit(120);

            $primary = $this->whatsApp->sendDocumentMessage(
                $recipientPhone,
                base64_decode((string) $payload['pdf_b64'], true) ?: '',
                (string) $payload['pdf_name'],
                'application/pdf',
                $body,
            );

            if (!($primary['success'] ?? false)) {
                return [
                    'success' => false,
                    'channel' => 'whatsapp',
                    'message' => 'Échec de l\'envoi WhatsApp du document principal.',
                    'errors' => $primary,
                    'status' => 422,
                ];
            }

            $attachmentBytes = $this->decodeNullableBase64($payload['attachment_b64'] ?? null);
            $attachmentName = $this->cleanNullableString($payload['attachment_name'] ?? null);
            $attachmentMime = $this->cleanNullableString($payload['attachment_mime'] ?? null) ?? 'application/octet-stream';
            $attachmentResult = null;

            if ($attachmentBytes !== null && $attachmentName !== null) {
                $attachmentResult = $this->whatsApp->sendDocumentMessage(
                    $recipientPhone,
                    $attachmentBytes,
                    $attachmentName,
                    $attachmentMime,
                    'Pièce justificative envoyée par ' . ($sender->display_name ?: 'Admin EdgPay'),
                );

                if (!($attachmentResult['success'] ?? false)) {
                    Log::warning('admin_document_dispatch.whatsapp_attachment_failed', [
                        'phone' => $recipientPhone,
                        'document_number' => $documentNumber,
                        'result' => $attachmentResult,
                    ]);
                }
            }

            Log::info('admin_document_dispatch.whatsapp_sent', [
                'document_number' => $documentNumber,
                'recipient' => $recipientPhone,
                'sender_id' => $sender->id,
            ]);

            return [
                'success' => true,
                'channel' => 'whatsapp',
                'message' => 'Document envoyé sur WhatsApp avec succès.',
                'recipient' => $recipientPhone,
                'primary' => $primary,
                'attachment' => $attachmentResult,
            ];
        } catch (Throwable $exception) {
            Log::error('admin_document_dispatch.whatsapp_failed', [
                'document_number' => $documentNumber,
                'recipient' => $recipientPhone,
                'sender_id' => $sender->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'channel' => 'whatsapp',
                'message' => 'Échec de l\'envoi WhatsApp. Vérifiez la configuration WhatsApp Cloud API ou réessayez.',
                'errors' => [
                    'whatsapp' => [$exception->getMessage()],
                ],
                'status' => 422,
            ];
        }
    }

    private function decodeNullableBase64(mixed $value): ?string
    {
        $encoded = trim((string) ($value ?? ''));
        if ($encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        return $decoded === false ? null : $decoded;
    }

    private function cleanNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }
}