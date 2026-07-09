<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reloadly\DetectOperatorRequest;
use App\Http\Requests\Reloadly\GetDataPlansRequest;
use App\Http\Requests\Reloadly\TopupAirtimeRequest;
use App\Interfaces\ReloadlyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReloadlyController extends Controller
{
    public function __construct(private ReloadlyServiceInterface $reloadlyService)
    {
    }

    public function authenticate(): JsonResponse
    {
        $result = $this->reloadlyService->authenticate();

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur d\'authentification Reloadly',
                $result['data'] ?? null,
                $result['status'] ?? 500,
                $result['business_code'] ?? 'RELOADLY_AUTH_FAILED'
            );
        }

        return ApiResponseClass::sendResponse($result['data'] ?? [], 'Authentification Reloadly OK');
    }

    public function detectOperator(DetectOperatorRequest $request): JsonResponse
    {
        $result = $this->reloadlyService->detectOperator(
            (string) $request->validated('phone'),
            (string) $request->validated('country_code', 'GN')
        );

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Opérateur non trouvé',
                $result['data'] ?? null,
                $result['status'] ?? 404,
                $result['business_code'] ?? 'OPERATOR_NOT_FOUND'
            );
        }

        return ApiResponseClass::sendResponse($result['data'] ?? [], 'Opérateur détecté');
    }

    public function dataPlans(GetDataPlansRequest $request): JsonResponse
    {
        $result = $this->reloadlyService->getDataPlans(
            (int) $request->validated('operator_id'),
            $request->validated('recipient_phone')
        );

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la récupération des forfaits data',
                $result['data'] ?? null,
                $result['status'] ?? 500,
                $result['business_code'] ?? 'DATA_PLANS_FETCH_FAILED'
            );
        }

        return ApiResponseClass::sendResponse($result['data'] ?? [], 'Forfaits data récupérés');
    }

    public function topupAirtime(TopupAirtimeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $payload = [
            'operatorId' => (int) $validated['operator_id'],
            'amount' => (float) $validated['amount'],
            'useLocalAmount' => (bool) $validated['use_local_amount'],
            'customIdentifier' => (string) $validated['custom_identifier'],
            'recipientPhone' => [
                'countryCode' => (string) ($validated['recipient_country_code'] ?? 'GN'),
                'number' => (string) $validated['recipient_phone'],
            ],
        ];

        if (!empty($validated['sender_phone'])) {
            $payload['senderPhone'] = [
                'countryCode' => (string) ($validated['sender_country_code'] ?? 'GN'),
                'number' => (string) $validated['sender_phone'],
            ];
        }

        $result = $this->reloadlyService->topupAirtime($payload);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Échec de la recharge airtime',
                $result['data'] ?? null,
                $result['status'] ?? 500,
                $result['business_code'] ?? 'AIRTIME_TOPUP_FAILED'
            );
        }

        return ApiResponseClass::sendResponse($result['data'] ?? [], 'Recharge airtime exécutée');
    }

    public function promotions(Request $request): JsonResponse
    {
        $request->validate([
            'operator_id' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->reloadlyService->getPromotions((int) $request->integer('operator_id'));

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la récupération des promotions',
                $result['data'] ?? null,
                $result['status'] ?? 500,
                $result['business_code'] ?? 'PROMOTIONS_FETCH_FAILED'
            );
        }

        return ApiResponseClass::sendResponse($result['data'] ?? [], 'Promotions récupérées');
    }

    public function commissions(Request $request): JsonResponse
    {
        $request->validate([
            'operator_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $operatorId = $request->filled('operator_id') ? (int) $request->integer('operator_id') : null;
        $result = $this->reloadlyService->getCommissions($operatorId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la récupération des commissions',
                $result['data'] ?? null,
                $result['status'] ?? 500,
                $result['business_code'] ?? 'COMMISSIONS_FETCH_FAILED'
            );
        }

        return ApiResponseClass::sendResponse($result['data'] ?? [], 'Commissions récupérées');
    }

    public function verifyTransaction(string $transactionId): JsonResponse
    {
        $result = $this->reloadlyService->verifyTransaction($transactionId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Transaction introuvable',
                $result['data'] ?? null,
                $result['status'] ?? 404,
                $result['business_code'] ?? 'TRANSACTION_NOT_FOUND'
            );
        }

        return ApiResponseClass::sendResponse($result['data'] ?? [], 'Transaction vérifiée');
    }
}
