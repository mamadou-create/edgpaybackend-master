<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NimbaSmsService
{
    private $baseUrl;
    private $headers;

    public function __construct()
    {
        $this->baseUrl = config('services.nimba.base_url');

        // Générer le Basic Auth dynamiquement
        $sid = config('services.nimba.sid');
        $secretToken = config('services.nimba.secret_token');
        $basicAuth = base64_encode("{$sid}:{$secretToken}");

        $this->headers = [
            'Authorization' => "Basic {$basicAuth}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // Log pour débogage (ne pas logger le token en production)
        // Log::info('NimbaSmsService initialized', [
        //     'base_url' => $this->baseUrl,
        //     'sid' => $sid,
        //     'secret_token' => $secretToken, // Attention: en production, ne loguez pas le token secret
        //     'authorization_header' => 'Basic ' . substr($basicAuth, 0, 10) . '...'
        // ]);
    }

    /**
     * Envoyer un SMS
     */
    public function sendSms(string $senderName, array $to, string $message, string $channel = 'sms'): array
    {
        // Log::info('Début envoi SMS Nimba', ['sender' => $senderName, 'to' => $to, 'message_length' => strlen($message)]);

        try {
            // Valider tous les numéros avant envoi
            foreach ($to as $phone) {
                if (!$this->validatePhoneNumber($phone)) {
                    return [
                        'success' => false,
                        'error' => "Numéro invalide: {$phone}. Format accepté: 623XXXXXX (9 chiffres)"
                    ];
                }
            }

            $payload = [
                'sender_name' => $senderName,
                'to' => $to,
                'message' => $message,
                'channel' => $channel,
            ];

            // Log::info('Envoi SMS Nimba', ['payload' => $payload]);

            $response = Http::withHeaders($this->headers)
                ->post("{$this->baseUrl}/messages", $payload);

            // Log::info('Réponse Nimba SMS', [
            //     'status' => $response->status(),
            //     'body' => $response->body()
            // ]);

            if ($response->successful()) {
                $result = $response->json();
                // Log::info('SMS envoyé avec succès', ['result' => $result]);
                return [
                    'success' => true,
                    'message_id' => $result['messageid'] ?? null,
                    'url' => $result['url'] ?? null,
                ];
            }

            // Log::error('Erreur envoi SMS', [
            //     'status' => $response->status(),
            //     'body' => $response->body()
            // ]);

            return [
                'success' => false,
                'error' => "Erreur API: {$response->status()}",
                'details' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error('Exception envoi SMS', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    /**
     * Lister les messages avec filtres
     */
    public function getMessages(array $filters = []): array
    {
        try {
            $queryParams = array_filter([
                'sent_at__gte' => $filters['sent_at__gte'] ?? null,
                'sent_at__lte' => $filters['sent_at__lte'] ?? null,
                'status' => $filters['status'] ?? null,
                'sender_name' => $filters['sender_name'] ?? null,
                'sent_at' => $filters['sent_at'] ?? null,
                'search' => $filters['search'] ?? null,
                'limit' => $filters['limit'] ?? 50,
                'offset' => $filters['offset'] ?? 0,
            ]);

            $response = Http::withHeaders($this->headers)
                ->timeout(30)
                ->get("{$this->baseUrl}/messages", $queryParams);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            // Log::error('Erreur récupération messages', [
            //     'status' => $response->status(),
            //     'body' => $response->body()
            // ]);

            return [
                'success' => false,
                'error' => "Erreur API: {$response->status()}",
                'details' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error('Exception récupération messages', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Obtenir les détails d'un message
     */
    public function getMessageDetails(string $messageId): array
    {
        try {
            $response = Http::withHeaders($this->headers)
                ->timeout(30)
                ->get("{$this->baseUrl}/messages/{$messageId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            // Log::error('Erreur détails message', [
            //     'message_id' => $messageId,
            //     'status' => $response->status(),
            //     'body' => $response->body()
            // ]);

            return [
                'success' => false,
                'error' => "Erreur API: {$response->status()}",
                'details' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error('Exception détails message', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Envoyer un SMS à un seul destinataire
     */
    public function sendSingleSms(string $senderName, string $to, string $message, string $channel = 'sms'): array
    {
        // Validation du numéro avant envoi
        if (!$this->validatePhoneNumber($to)) {
            return [
                'success' => false,
                'error' => "Numéro invalide: {$to}. Format accepté: 623XXXXXX (9 chiffres)"
            ];
        }

        return $this->sendSms($senderName, [$to], $message, $channel);
    }

    /**
     * Valider le format des numéros - UNIQUEMENT 623XXXXXX (9 chiffres)
     */
    public function validatePhoneNumber(string $phone): bool
    {
        // Nettoyer le numéro
        $cleaned = preg_replace('/\s+/', '', $phone);

        // Vérifier exactement 9 chiffres qui commencent par 62, 65, 66, etc.
        // Format: 623XXXXXX, 624XXXXXX, 625XXXXXX, etc. (opérateurs guinéens)
        return preg_match('/^(62|65|66)[0-9]{7}$/', $cleaned) === 1;
    }

    /**
     * Formater un numéro de téléphone - PAS de formatage supplémentaire
     * On garde le format 623XXXXXX tel quel
     */
    public function formatPhoneNumber(string $phone): string
    {
        // Retourner le numéro nettoyé mais sans ajouter d'indicatif
        return preg_replace('/\s+/', '', $phone);
    }

    /**
     * Valider et formater un tableau de numéros
     */
    public function validateAndFormatNumbers(array $phones): array
    {
        $validNumbers = [];

        foreach ($phones as $phone) {
            if ($this->validatePhoneNumber($phone)) {
                $validNumbers[] = $this->formatPhoneNumber($phone);
            }
        }

        return $validNumbers;
    }

    /**
     * Vérifier si un numéro appartient à un opérateur guinéen valide
     */
    public function isGuineanOperator(string $phone): bool
    {
        $cleaned = preg_replace('/\s+/', '', $phone);

        // Opérateurs mobiles en Guinée
        $operators = [
            '62', // Orange
            '65', // MTN
            '66', // Cellcom
            '60', // Intercel
        ];

        foreach ($operators as $prefix) {
            if (str_starts_with($cleaned, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tester la connexion à l'API
     */
    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders($this->headers)
                ->timeout(10)
                ->get("{$this->baseUrl}/messages", ['limit' => 1]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'message' => $response->successful() ? 'Connexion réussie' : 'Erreur de connexion'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
