<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppTextMessageJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudApiService
{
    public function normalizeInboundPayload(array $payload): array
    {
        if (isset($payload['phone'], $payload['message'])) {
            $phone = (string) $payload['phone'];

            return [
                'phone' => $this->normalizePhone($phone),
                'reply_phone' => $this->normalizePhoneForOutbound($phone),
                'message' => trim((string) $payload['message']),
                'timestamp' => $payload['timestamp'] ?? now()->toIso8601String(),
                'provider_message_id' => $payload['message_id'] ?? null,
                'raw' => $payload,
            ];
        }

        $message = Arr::get($payload, 'entry.0.changes.0.value.messages.0', []);
        $from = (string) ($message['from'] ?? '');
        $text = (string) Arr::get($message, 'text.body', '');

        return [
            'phone' => $this->normalizePhone($from),
            'reply_phone' => $this->normalizePhoneForOutbound($from),
            'message' => trim($text),
            'timestamp' => $message['timestamp'] ?? now()->toIso8601String(),
            'provider_message_id' => $message['id'] ?? null,
            'raw' => $payload,
        ];
    }

    public function sendTextMessage(string $phone, string $message): array
    {
        if (config('whatsapp.queue_outbound', true)) {
            SendWhatsAppTextMessageJob::dispatch($phone, $message)
                ->onQueue((string) config('whatsapp.outbound_queue', 'whatsapp'));

            return [
                'success' => true,
                'queued' => true,
                'message' => 'Message WhatsApp mis en file d\'attente.',
            ];
        }

        return $this->sendTextMessageNow($phone, $message);
    }

    public function sendTextMessageNow(string $phone, string $message): array
    {
        $token = config('whatsapp.access_token');
        $phoneNumberId = config('whatsapp.phone_number_id');
        $graphUrl = rtrim((string) config('whatsapp.graph_url'), '/');

        if (!$token || !$phoneNumberId) {
            Log::info('whatsapp.outbound.mock', [
                'phone' => $phone,
                'message' => $message,
            ]);

            return [
                'success' => true,
                'mocked' => true,
                'message' => 'Message journalisé localement, credentials WhatsApp absents.',
            ];
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->post("{$graphUrl}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'text',
                'text' => ['body' => $message],
            ]);

        if (!$response->successful()) {
            Log::warning('whatsapp.outbound.failed', [
                'phone' => $phone,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        return [
            'success' => $response->successful(),
            'status' => $response->status(),
            'data' => $response->json(),
        ];
    }

    public function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($normalized, '224') && strlen($normalized) > 9) {
            $normalized = substr($normalized, -9);
        }

        return $normalized;
    }

    public function normalizePhoneForOutbound(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';
        $defaultCountryCode = (string) config('whatsapp.default_country_code', '224');

        if ($normalized === '') {
            return '';
        }

        if ($defaultCountryCode !== '' && !str_starts_with($normalized, $defaultCountryCode) && strlen($normalized) <= 9) {
            return $defaultCountryCode . $normalized;
        }

        return $normalized;
    }
}
