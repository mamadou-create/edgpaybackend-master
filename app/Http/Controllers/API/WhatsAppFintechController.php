<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppFintechService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class WhatsAppFintechController extends Controller
{
    public function __construct(private WhatsAppFintechService $whatsAppFintechService) {}

    public function createUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
        ]);

        try {
            $result = $this->whatsAppFintechService->createUserForWhatsApp(
                $validated['phone'],
                $validated['name'],
                $validated['date_of_birth'],
                $validated['pin'],
            );

            return ApiResponseClass::created($result, 'Compte WhatsApp créé avec succès');
        } catch (RuntimeException $e) {
            return ApiResponseClass::sendError($e->getMessage());
        }
    }

    public function linkAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'account_phone' => ['required', 'string', 'max:20'],
        ]);

        try {
            $result = $this->whatsAppFintechService->linkExistingAccount(
                $validated['phone'],
                $validated['account_phone'],
            );

            return ApiResponseClass::sendResponse($result, 'OTP de liaison envoyé');
        } catch (RuntimeException $e) {
            return ApiResponseClass::sendError($e->getMessage());
        }
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'otp' => ['required', 'string', 'max:10'],
        ]);

        try {
            $result = $this->whatsAppFintechService->verifyLinkOtp(
                $validated['phone'],
                $validated['otp'],
            );

            return ApiResponseClass::sendResponse($result, 'Compte existant lié avec succès');
        } catch (RuntimeException $e) {
            return ApiResponseClass::sendError($e->getMessage());
        }
    }

    public function balance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
        ]);

        try {
            $result = $this->whatsAppFintechService->getWalletBalance(
                $validated['phone'],
                $validated['pin'],
            );

            return ApiResponseClass::sendResponse($result, 'Solde récupéré avec succès');
        } catch (RuntimeException $e) {
            return ApiResponseClass::sendError($e->getMessage());
        }
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'receiver_phone' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'integer', 'min:1'],
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
            'otp' => ['nullable', 'string', 'max:10'],
        ]);

        try {
            $result = $this->whatsAppFintechService->sendMoney(
                $validated['phone'],
                $validated['receiver_phone'],
                (int) $validated['amount'],
                $validated['pin'],
                $validated['otp'] ?? null,
            );

            return ApiResponseClass::sendResponse($result, 'Traitement du transfert WhatsApp terminé');
        } catch (RuntimeException $e) {
            return ApiResponseClass::sendError($e->getMessage());
        }
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'pin' => ['required', 'regex:/^\d{4,6}$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $result = $this->whatsAppFintechService->getTransactionHistory(
                $validated['phone'],
                $validated['pin'],
                (int) ($validated['limit'] ?? 5),
            );

            return ApiResponseClass::sendResponse($result, 'Historique WhatsApp récupéré avec succès');
        } catch (RuntimeException $e) {
            return ApiResponseClass::sendError($e->getMessage());
        }
    }

    public function support(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'message' => ['required', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->whatsAppFintechService->createSupportTicket(
                $validated['phone'],
                $validated['message'],
                $validated['reason'] ?? null,
            );

            return ApiResponseClass::sendResponse($result, 'Demande support WhatsApp créée avec succès');
        } catch (RuntimeException $e) {
            return ApiResponseClass::sendError($e->getMessage());
        }
    }
}
