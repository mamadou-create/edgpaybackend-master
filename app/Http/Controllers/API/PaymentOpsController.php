<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Services\PaymentOpsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentOpsController extends Controller
{
    public function __construct(private PaymentOpsService $opsService)
    {
    }

    public function health(): JsonResponse
    {
        return ApiResponseClass::sendResponse(
            $this->opsService->healthOverview(),
            'Vue santé paiements récupérée'
        );
    }

    public function logs(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'service' => ['sometimes', 'string', 'max:100'],
            'user_id' => ['sometimes', 'uuid'],
            'status_code' => ['sometimes', 'integer', 'min:100', 'max:599'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'idempotency_key' => ['sometimes', 'string', 'max:255'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $result = $this->opsService->listOpsApiLogs([
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 50),
            'service' => $request->input('service'),
            'user_id' => $request->input('user_id'),
            'status_code' => $request->input('status_code'),
            'correlation_id' => $request->input('correlation_id'),
            'idempotency_key' => $request->input('idempotency_key'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ]);

        return ApiResponseClass::sendResponse($result, 'Logs ops récupérés');
    }

    public function logDetails(string $id): JsonResponse
    {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return ApiResponseClass::sendError(
                'Identifiant de log invalide',
                null,
                422,
                'INVALID_LOG_ID'
            );
        }

        $result = $this->opsService->getOpsApiLogDetails($id);
        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Log introuvable',
                null,
                (int) ($result['status'] ?? 404),
                $result['business_code'] ?? 'OPS_LOG_NOT_FOUND'
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'] ?? [],
            'Détail du log ops récupéré',
            (int) ($result['status'] ?? 200)
        );
    }

    public function failures(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'order_type' => ['sometimes', 'in:ALL,AIRTIME,DATA,all,airtime,data'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $payload = [
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 50),
            'order_type' => $request->string('order_type', 'ALL')->value(),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ];

        return ApiResponseClass::sendResponse(
            $this->opsService->failedOrders($payload),
            'Liste des échecs récupérée'
        );
    }

    public function replay(string $paymentTransactionId): JsonResponse
    {
        $result = $this->opsService->replayPaymentTransaction($paymentTransactionId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Replay refusé',
                $result['data'] ?? null,
                (int) ($result['status'] ?? 400),
                $result['business_code'] ?? 'REPLAY_REJECTED'
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'] ?? [],
            $result['message'] ?? 'Replay planifié',
            (int) ($result['status'] ?? 202)
        );
    }

    public function replayBatch(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['sometimes', 'in:ALL,AIRTIME,DATA,all,airtime,data'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'dry_run' => ['sometimes', 'boolean'],
            'require_confirm' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $dryRun = $request->boolean('dry_run');
        $requireConfirm = $request->boolean('require_confirm');

        if (!$dryRun && !$requireConfirm) {
            return ApiResponseClass::sendError(
                'Confirmation explicite requise pour exécuter le replay batch',
                null,
                422,
                'BATCH_CONFIRMATION_REQUIRED'
            );
        }

        $result = $this->opsService->replayFailedOrdersBatch([
            'type' => $request->string('type', 'ALL')->value(),
            'limit' => $request->integer('limit', 25),
            'dry_run' => $dryRun,
            'require_confirm' => $requireConfirm,
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ]);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Replay batch refusé',
                $result['data'] ?? null,
                (int) ($result['status'] ?? 400),
                $result['business_code'] ?? 'BATCH_REPLAY_REJECTED'
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'] ?? [],
            $result['message'] ?? 'Replay batch planifié',
            (int) ($result['status'] ?? 202)
        );
    }

    public function exportFailuresCsv(Request $request): StreamedResponse
    {
        $request->validate([
            'order_type' => ['sometimes', 'in:ALL,AIRTIME,DATA,all,airtime,data'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:5000'],
        ]);

        $result = $this->opsService->exportFailedOrders([
            'order_type' => $request->string('order_type', 'ALL')->value(),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'limit' => $request->integer('limit', 1000),
        ]);

        $rows = $result['items'] ?? [];
        $filename = 'payment_failures_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['order_type', 'order_id', 'payment_transaction_id', 'error_code', 'error_message', 'updated_at']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['order_type'] ?? '',
                    $row['order_id'] ?? '',
                    $row['payment_transaction_id'] ?? '',
                    $row['error_code'] ?? '',
                    $row['error_message'] ?? '',
                    $row['updated_at'] ?? '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
