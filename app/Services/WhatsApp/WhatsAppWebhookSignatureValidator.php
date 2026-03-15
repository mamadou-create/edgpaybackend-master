<?php

namespace App\Services\WhatsApp;

class WhatsAppWebhookSignatureValidator
{
    public function isValid(?string $signatureHeader, string $payload): bool
    {
        if (!config('whatsapp.validate_signature', false)) {
            return true;
        }

        $appSecret = (string) config('whatsapp.app_secret', '');
        if ($appSecret === '' || $signatureHeader === null || !str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

        return hash_equals($expectedSignature, trim($signatureHeader));
    }
}