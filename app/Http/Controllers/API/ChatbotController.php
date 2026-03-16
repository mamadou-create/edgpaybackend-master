<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function __construct(private ChatbotService $chatbotService) {}

    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'selected_agent' => 'nullable|string|max:50',
        ]);

        $response = $this->chatbotService->handle(
            $request->user(),
            (string) $validated['message'],
            $validated['selected_agent'] ?? null,
        );

        return ApiResponseClass::sendResponse($response, 'Réponse du chatbot générée avec succès');
    }
}