<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendWhatsAppTextMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $phone,
        public string $message,
    ) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(WhatsAppCloudApiService $gateway): void
    {
        $result = $gateway->sendTextMessageNow($this->phone, $this->message);

        if (!($result['success'] ?? false)) {
            $providerErrorCode = (int) data_get($result, 'data.error.code', 0);

            if ($providerErrorCode === 131030) {
                Log::warning('whatsapp.outbound.recipient_not_allowed', [
                    'phone' => $this->phone,
                    'message' => $this->message,
                    'provider_code' => $providerErrorCode,
                    'provider_response' => $result,
                ]);

                return;
            }

            throw new RuntimeException('Echec envoi WhatsApp: ' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('whatsapp.outbound.job_failed', [
            'phone' => $this->phone,
            'message' => $this->message,
            'error' => $exception?->getMessage(),
        ]);
    }
}