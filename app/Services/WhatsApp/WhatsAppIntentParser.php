<?php

namespace App\Services\WhatsApp;

class WhatsAppIntentParser
{
    public function parse(string $message): array
    {
        $normalized = $this->normalize($message);

        $amount = null;
        if (preg_match('/\b(\d{3,12})\b/', $normalized, $amountMatch)) {
            $amount = (int) $amountMatch[1];
        }

        $phone = null;
        if (preg_match('/(?:\+?224)?\s*(6\d{8})/', preg_replace('/\s+/', '', $normalized), $phoneMatch)) {
            $phone = $phoneMatch[1];
        }

        $recipientName = null;
        if (preg_match('/(?:a|à)\s+([a-z\-\' ]{2,})$/u', trim($normalized), $nameMatch)) {
            $candidate = trim($nameMatch[1]);
            if (!preg_match('/^6\d{8}$/', preg_replace('/\s+/', '', $candidate))) {
                $recipientName = $candidate;
            }
        }

        $intent = match (true) {
            in_array($normalized, ['1', 'creer un compte', 'créer un compte'], true) => 'CREATE_ACCOUNT',
            in_array($normalized, ['2', 'associer un compte', 'lier un compte'], true) => 'LINK_ACCOUNT',
            str_contains($normalized, 'bonjour') || str_contains($normalized, 'salut') || str_contains($normalized, 'bonsoir') => 'GREETING',
            in_array($normalized, ['aide', 'menu aide', 'que peux tu faire'], true) => 'HELP',
            str_contains($normalized, 'merci') => 'THANKS',
            str_contains($normalized, 'comment fonctionne') || str_contains($normalized, 'comment marche') => 'SERVICE_INFO',
            str_contains($normalized, 'frais') || str_contains($normalized, 'tarif') || str_contains($normalized, 'commission') => 'FEES_INFO',
            str_contains($normalized, 'otp') || str_contains($normalized, 'pin') || str_contains($normalized, 'securite') || str_contains($normalized, 'sécurité') => 'SECURITY_HELP',
            in_array($normalized, ['3', 'solde', 'verifier solde', 'vérifier solde'], true) => 'CHECK_BALANCE',
            in_array($normalized, ['5', 'historique', 'historique transactions'], true) => 'TRANSACTION_HISTORY',
            in_array($normalized, ['6', 'support', 'support client'], true) => 'SUPPORT',
            str_contains($normalized, 'envoyer') || str_contains($normalized, 'transfert') || $normalized === '4' => 'SEND_MONEY',
            in_array($normalized, ['menu', 'retour menu', '0'], true) => 'MENU',
            in_array($normalized, ['annuler', 'cancel'], true) => 'CANCEL',
            default => 'UNKNOWN',
        };

        return [
            'intent' => $intent,
            'entities' => [
                'amount' => $amount,
                'phone' => $phone,
                'recipient_name' => $recipientName,
            ],
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return str_replace(['é', 'è', 'ê', 'à', 'â', 'ù', 'û', 'î', 'ï', 'ô', 'ö', 'ç'], ['e', 'e', 'e', 'a', 'a', 'u', 'u', 'i', 'i', 'o', 'o', 'c'], $value);
    }
}
