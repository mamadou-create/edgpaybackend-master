<?php

namespace App\Services\WhatsApp;

class WhatsAppIntentParser
{
    public function parse(string $message): array
    {
        $normalized = mb_strtolower(trim($message));

        $amount = null;
        if (preg_match('/\b(\d{3,12})\b/', $normalized, $amountMatch)) {
            $amount = (int) $amountMatch[1];
        }

        $phone = null;
        if (preg_match('/(?:\+?224)?\s*(6\d{8})/', preg_replace('/\s+/', '', $normalized), $phoneMatch)) {
            $phone = $phoneMatch[1];
        }

        $intent = match (true) {
            in_array($normalized, ['1', 'creer un compte', 'créer un compte'], true) => 'CREATE_ACCOUNT',
            in_array($normalized, ['2', 'associer un compte', 'lier un compte'], true) => 'LINK_ACCOUNT',
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
            ],
        ];
    }
}
