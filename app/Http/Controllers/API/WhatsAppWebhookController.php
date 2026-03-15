<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppFintechService;
use App\Services\WhatsApp\WhatsAppWebhookSignatureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private WhatsAppFintechService $whatsAppFintechService,
        private WhatsAppWebhookSignatureValidator $signatureValidator,
    ) {}

    public function verify(Request $request)
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($mode === 'subscribe' && $token !== '' && $token === config('whatsapp.verify_token')) {
            return response($challenge, 200);
        }

        return response('Invalid verify token', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        if (!$this->signatureValidator->isValid(
            $request->header('X-Hub-Signature-256'),
            $request->getContent(),
        )) {
            return ApiResponseClass::forbidden('Signature WhatsApp invalide.');
        }

        $response = $this->whatsAppFintechService->handleWebhook($request->all());

        return ApiResponseClass::sendResponse($response, 'Webhook WhatsApp traité avec succès');
    }
}
