<?php

namespace App\Services;

class WebhookSignatureService
{
    public function isValid(string $provider, ?string $signatureHeader, string $rawPayload): bool
    {
        $secret = (string) config("services.mobile_money.providers.{$provider}.webhook_secret", '');

        if ($secret === '') {
            return (bool) config('services.mobile_money.allow_unsigned_local', false);
        }

        if ($signatureHeader === null || trim($signatureHeader) === '') {
            return false;
        }

        $incoming = trim($signatureHeader);
        if (str_starts_with($incoming, 'sha256=')) {
            $incoming = substr($incoming, 7);
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals(strtolower($expected), strtolower($incoming));
    }
}
