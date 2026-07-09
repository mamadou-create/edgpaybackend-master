<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\CreateAirtimePurchaseIntentRequest;
use App\Http\Requests\Payments\CreateDataPurchaseIntentRequest;
use App\Services\PaymentOrchestrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentOrchestrationController extends Controller
{
    public function __construct(private PaymentOrchestrationService $orchestrationService)
    {
    }

    public function createAirtimeIntent(CreateAirtimePurchaseIntentRequest $request): JsonResponse
    {
        try {
            $result = $this->orchestrationService->createAirtimePurchaseIntent(
                $request->validated(),
                $request->user()
            );
        } catch (\RuntimeException $e) {
            $walletErrorResponse = $this->mapWalletRuntimeException($e);
            if ($walletErrorResponse !== null) {
                return $walletErrorResponse;
            }

            throw $e;
        }

        $intentStatus = $result['payment_transaction']->status === 'CONFIRMED'
            ? 'PAYMENT_CONFIRMED'
            : 'PENDING_PAYMENT';

        return ApiResponseClass::created([
            'transaction_id' => $result['transaction']->id,
            'transaction_reference' => $result['transaction']->reference,
            'payment_reference' => $result['payment_transaction']->payment_reference,
            'payment_transaction_id' => $result['payment_transaction']->id,
            'airtime_order_id' => $result['airtime_order']->id,
            'expires_at' => $result['payment_transaction']->expires_at,
            'status' => $intentStatus,
            'wallet_balance_before' => $result['wallet_balance_before'] ?? null,
            'wallet_balance_after' => $result['wallet_balance_after'] ?? null,
            'wallet_debited_amount' => $result['wallet_debited_amount'] ?? null,
            'wallet_currency' => $result['wallet_balance_after'] !== null
                ? $result['payment_transaction']->currency
                : null,
        ], 'Intention de recharge airtime créée');
    }

    public function createDataIntent(CreateDataPurchaseIntentRequest $request): JsonResponse
    {
        try {
            $result = $this->orchestrationService->createDataPurchaseIntent(
                $request->validated(),
                $request->user()
            );
        } catch (\RuntimeException $e) {
            $walletErrorResponse = $this->mapWalletRuntimeException($e);
            if ($walletErrorResponse !== null) {
                return $walletErrorResponse;
            }

            throw $e;
        }

        $intentStatus = $result['payment_transaction']->status === 'CONFIRMED'
            ? 'PAYMENT_CONFIRMED'
            : 'PENDING_PAYMENT';

        return ApiResponseClass::created([
            'transaction_id' => $result['transaction']->id,
            'transaction_reference' => $result['transaction']->reference,
            'payment_reference' => $result['payment_transaction']->payment_reference,
            'payment_transaction_id' => $result['payment_transaction']->id,
            'data_order_id' => $result['data_order']->id,
            'expires_at' => $result['payment_transaction']->expires_at,
            'status' => $intentStatus,
            'wallet_balance_before' => $result['wallet_balance_before'] ?? null,
            'wallet_balance_after' => $result['wallet_balance_after'] ?? null,
            'wallet_debited_amount' => $result['wallet_debited_amount'] ?? null,
            'wallet_currency' => $result['wallet_balance_after'] !== null
                ? $result['payment_transaction']->currency
                : null,
        ], 'Intention de recharge data créée');
    }

    public function paymentWebhook(Request $request, string $provider): JsonResponse
    {
        $signature = $request->header('X-Signature')
            ?? $request->header('X-Hub-Signature-256')
            ?? $request->header('X-Webhook-Signature');

        $result = $this->orchestrationService->handlePaymentWebhook(
            $provider,
            $request->all(),
            $request->headers->all(),
            $request->getContent(),
            $signature
        );

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur de webhook',
                $result['data'] ?? null,
                (int) ($result['status'] ?? 400),
                $result['business_code'] ?? 'WEBHOOK_PROCESSING_ERROR'
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'] ?? [],
            $result['message'] ?? 'Webhook traité avec succès',
            (int) ($result['status'] ?? 200)
        );
    }

    private function mapWalletRuntimeException(\RuntimeException $e): ?JsonResponse
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Solde wallet insuffisant')) {
            return ApiResponseClass::sendError(
                'Solde wallet insuffisant.',
                null,
                422,
                'INSUFFICIENT_WALLET_BALANCE'
            );
        }

        if (str_contains($message, 'Wallet client introuvable')) {
            return ApiResponseClass::sendError(
                'Wallet client introuvable.',
                null,
                404,
                'WALLET_NOT_FOUND'
            );
        }

        if (str_contains($message, 'Utilisateur requis pour un paiement wallet')) {
            return ApiResponseClass::sendError(
                'Utilisateur non authentifié pour un paiement wallet.',
                null,
                401,
                'WALLET_USER_REQUIRED'
            );
        }

        return null;
    }
}
