<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Services\NimbaSmsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SmsController extends Controller
{
    private $smsService;

    public function __construct(NimbaSmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * 📱 Envoyer un SMS
     */
    public function sendSms(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sender_name' => 'required|string|max:30',
            'to' => 'required|array|max:30',
            'to.*' => 'required|string|min:9|max:9',
            'message' => 'required|string|max:665',
            'channel' => 'sometimes|string|in:sms,whatsapp,email'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données d\'envoi SMS invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Valider que tous les numéros sont au format 623XXXXXX
        foreach ($request->to as $phone) {
            if (!$this->smsService->validatePhoneNumber($phone)) {
                return ApiResponseClass::sendError(
                    "Numéro invalide: {$phone}. Format accepté: 623XXXXXX (9 chiffres commençant par 62, 65, 66)",
                    [],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        // Formater les numéros (nettoyage simple)
        $formattedNumbers = $this->smsService->validateAndFormatNumbers($request->to);

        $result = $this->smsService->sendSms(
            $request->sender_name,
            $formattedNumbers,
            $request->message,
            $request->channel ?? 'sms'
        );

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de l\'envoi du SMS',
                $result['details'] ?? [],
                Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result,
            'SMS envoyé avec succès'
        );
    }

    /**
     * 📱 Envoyer un SMS simple
     */
    public function sendQuickSms(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sender_name' => 'required|string|max:30',
            'to' => 'required|string|min:9|max:9',
            'message' => 'required|string|max:665',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données d\'envoi SMS invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!$this->smsService->validatePhoneNumber($request->to)) {
            return ApiResponseClass::sendError(
                "Numéro invalide: {$request->to}. Format accepté: 623XXXXXX (9 chiffres commençant par 62, 65, 66)",
                [],
                Response::HTTP_BAD_REQUEST
            );
        }

        $result = $this->smsService->sendSingleSms(
            $request->sender_name,
            $request->to,
            $request->message
        );

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de l\'envoi du SMS',
                $result['details'] ?? [],
                Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result,
            'SMS envoyé avec succès'
        );
    }

    /**
     * 📋 Lister les messages
     */
    public function getMessages(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sent_at__gte' => 'sometimes|date',
            'sent_at__lte' => 'sometimes|date',
            'status' => 'sometimes|string',
            'sender_name' => 'sometimes|string',
            'sent_at' => 'sometimes|date',
            'search' => 'sometimes|string',
            'limit' => 'sometimes|integer|min:1|max:100',
            'offset' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Paramètres de filtre invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->smsService->getMessages($validator->validated());

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la récupération des messages',
                $result['details'] ?? [],
                Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Liste des messages récupérée avec succès'
        );
    }

    /**
     * 🔍 Obtenir les détails d'un message
     */
    public function getMessageDetails(string $messageId): JsonResponse
    {
        $validator = Validator::make(['message_id' => $messageId], [
            'message_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'ID de message invalide.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->smsService->getMessageDetails($messageId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la récupération des détails du message',
                $result['details'] ?? [],
                Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Détails du message récupérés avec succès'
        );
    }

    /**
     * 🔧 Vérifier la santé du service SMS
     */
    public function healthCheck(): JsonResponse
    {
        try {
            // Tester l'authentification en récupérant les messages récents
            $result = $this->smsService->getMessages(['limit' => 1]);

            if ($result['success']) {
                return ApiResponseClass::sendResponse(
                    ['status' => 'healthy', 'authenticated' => true],
                    'Service SMS opérationnel'
                );
            }

            return ApiResponseClass::sendError(
                'Service SMS accessible mais erreur d\'authentification',
                $result['details'] ?? [],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        } catch (\Exception $e) {
            Log::error('Erreur health check SMS', ['error' => $e->getMessage()]);
            
            return ApiResponseClass::sendError(
                'Service SMS inaccessible: ' . $e->getMessage(),
                [],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }
    }

    /**
     * 📊 Statistiques des SMS
     */
    public function getStats(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Paramètres de statistiques invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $filters = $validator->validated();
        
        // Récupérer les messages avec filtres de date
        $messagesResult = $this->smsService->getMessages([
            'sent_at__gte' => $filters['start_date'] ?? null,
            'sent_at__lte' => $filters['end_date'] ?? null,
            'limit' => 1000 // Augmenter la limite pour les stats
        ]);

        if (!$messagesResult['success']) {
            return ApiResponseClass::sendError(
                'Erreur lors de la récupération des statistiques',
                $messagesResult['details'] ?? [],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Calculer les statistiques basiques
        $data = $messagesResult['data'] ?? [];
        $stats = [
            'total_messages' => $data['count'] ?? 0,
            'period' => [
                'start' => $filters['start_date'] ?? null,
                'end' => $filters['end_date'] ?? null,
            ]
        ];

        return ApiResponseClass::sendResponse(
            $stats,
            'Statistiques récupérées avec succès'
        );
    }
}