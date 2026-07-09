<?php

namespace App\Services;

use App\Jobs\ExecuteReloadlyOrderJob;
use App\Models\ApiLog;
use App\Models\AirtimeOrder;
use App\Models\DataOrder;
use App\Models\PaymentTransaction;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentOpsService
{
    public const MAX_FAILURES_PAGE_SIZE = 200;

    public const MAX_BATCH_REPLAY = 100;

    public const MAX_FAILURES_EXPORT = 5000;

    public const MAX_LOGS_PAGE_SIZE = 200;

    private const REDACTED = '***REDACTED***';

    private const SENSITIVE_KEYS = [
        'authorization',
        'proxy-authorization',
        'client_secret',
        'secret',
        'password',
        'passcode',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'apikey',
        'x-api-key',
        'otp',
        'pin',
    ];

    public function healthOverview(): array
    {
        $now = now();
        $last24h = $now->copy()->subDay();

        $webhooks24h = WebhookLog::query()->where('received_at', '>=', $last24h);
        $totalWebhooks = (clone $webhooks24h)->count();
        $failedWebhooks = (clone $webhooks24h)->where('status', 'FAILED')->count();
        $invalidSignatures = (clone $webhooks24h)->where('signature_valid', false)->count();

        $payments24h = PaymentTransaction::query()->where('created_at', '>=', $last24h);
        $confirmedPayments = (clone $payments24h)->where('status', 'CONFIRMED')->count();
        $failedPayments = (clone $payments24h)->where('status', 'FAILED')->count();
        $pendingPayments = (clone $payments24h)->where('status', 'PENDING')->count();

        $airtime24h = AirtimeOrder::query()->where('created_at', '>=', $last24h);
        $data24h = DataOrder::query()->where('created_at', '>=', $last24h);

        $summary = [
            'period' => '24h',
            'webhooks' => [
                'total' => $totalWebhooks,
                'failed' => $failedWebhooks,
                'invalid_signatures' => $invalidSignatures,
            ],
            'payments' => [
                'confirmed' => $confirmedPayments,
                'failed' => $failedPayments,
                'pending' => $pendingPayments,
            ],
            'orders' => [
                'airtime_success' => (clone $airtime24h)->where('status', 'SUCCESS')->count(),
                'airtime_failed' => (clone $airtime24h)->where('status', 'FAILED')->count(),
                'data_success' => (clone $data24h)->where('status', 'SUCCESS')->count(),
                'data_failed' => (clone $data24h)->where('status', 'FAILED')->count(),
            ],
        ];

        $summary['sla'] = [
            'webhook_failure_rate_percent' => $totalWebhooks > 0 ? round(($failedWebhooks / $totalWebhooks) * 100, 2) : 0,
            'payment_failure_rate_percent' => ($confirmedPayments + $failedPayments) > 0
                ? round(($failedPayments / ($confirmedPayments + $failedPayments)) * 100, 2)
                : 0,
        ];

        return $summary;
    }

    public function listOpsApiLogs(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 50), self::MAX_LOGS_PAGE_SIZE));
        $service = isset($filters['service']) ? trim((string) $filters['service']) : null;
        $userId = isset($filters['user_id']) ? trim((string) $filters['user_id']) : null;
        $statusCode = isset($filters['status_code']) ? (int) $filters['status_code'] : null;
        $correlationId = isset($filters['correlation_id']) ? trim((string) $filters['correlation_id']) : null;
        $idempotencyKey = isset($filters['idempotency_key']) ? trim((string) $filters['idempotency_key']) : null;
        $from = !empty($filters['from']) ? now()->parse((string) $filters['from']) : null;
        $to = !empty($filters['to']) ? now()->parse((string) $filters['to']) : null;

        $query = ApiLog::query()
            ->where('service', 'like', 'ops-payments-%')
            ->when($service, fn ($q) => $q->where('service', $service))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($statusCode, fn ($q) => $q->where('status_code', $statusCode))
            ->when($correlationId, fn ($q) => $q->where('correlation_id', $correlationId))
            ->when($idempotencyKey, fn ($q) => $q->where('idempotency_key', $idempotencyKey))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $total = (clone $query)->count();
        $items = $query
            ->latest('created_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (ApiLog $log) => [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'service' => $log->service,
                'endpoint' => $log->endpoint,
                'method' => $log->method,
                'status_code' => $log->status_code,
                'correlation_id' => $log->correlation_id,
                'idempotency_key' => $this->maskIdempotencyKey($log->idempotency_key),
                'request_ip' => $log->request_ip,
                'error_message' => $log->error_message,
                'request_headers' => $this->maskSensitiveData(is_array($log->request_headers) ? $log->request_headers : []),
                'request_body' => $this->maskSensitiveData(is_array($log->request_body) ? $log->request_body : []),
                'response_body' => $log->response_body,
                'created_at' => optional($log->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                'filters' => [
                    'service' => $service,
                    'user_id' => $userId,
                    'status_code' => $statusCode,
                    'correlation_id' => $correlationId,
                    'idempotency_key' => $idempotencyKey,
                    'from' => $from?->toIso8601String(),
                    'to' => $to?->toIso8601String(),
                ],
            ],
        ];
    }

    public function getOpsApiLogDetails(string $logId): array
    {
        $log = ApiLog::query()
            ->where('service', 'like', 'ops-payments-%')
            ->where('id', $logId)
            ->first();

        if (!$log) {
            return [
                'success' => false,
                'status' => 404,
                'business_code' => 'OPS_LOG_NOT_FOUND',
                'message' => 'Log ops introuvable',
            ];
        }

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'service' => $log->service,
                'endpoint' => $log->endpoint,
                'method' => $log->method,
                'status_code' => $log->status_code,
                'duration_ms' => $log->duration_ms,
                'correlation_id' => $log->correlation_id,
                'idempotency_key' => $this->maskIdempotencyKey($log->idempotency_key),
                'request_ip' => $log->request_ip,
                'request_headers' => $this->maskSensitiveData(is_array($log->request_headers) ? $log->request_headers : []),
                'request_body' => $this->maskSensitiveData(is_array($log->request_body) ? $log->request_body : []),
                'response_body' => is_array($log->response_body) ? $log->response_body : [],
                'error_message' => $log->error_message,
                'created_at' => optional($log->created_at)->toIso8601String(),
                'updated_at' => optional($log->updated_at)->toIso8601String(),
            ],
        ];
    }

    public function failedOrders(array $filters = []): array
    {
        $orderType = strtoupper((string) ($filters['order_type'] ?? 'ALL'));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 50), self::MAX_FAILURES_PAGE_SIZE));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $from = !empty($filters['from']) ? now()->parse((string) $filters['from']) : null;
        $to = !empty($filters['to']) ? now()->parse((string) $filters['to']) : null;

        $combined = $this->collectFailedOrders($orderType, $from, $to);

        $sorted = $combined
            ->sortByDesc('updated_at')
            ->values()
            ->all();

        $total = count($sorted);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($sorted, $offset, $perPage);

        return [
            'items' => array_values($items),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                'order_type' => $orderType,
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
        ];
    }

    public function exportFailedOrders(array $filters = []): array
    {
        $orderType = strtoupper((string) ($filters['order_type'] ?? 'ALL'));
        $from = !empty($filters['from']) ? now()->parse((string) $filters['from']) : null;
        $to = !empty($filters['to']) ? now()->parse((string) $filters['to']) : null;
        $requestedLimit = max(1, (int) ($filters['limit'] ?? 1000));
        $limit = min($requestedLimit, self::MAX_FAILURES_EXPORT);

        $items = $this->collectFailedOrders($orderType, $from, $to)
            ->take($limit)
            ->values()
            ->all();

        $result = [
            'items' => $items,
            'meta' => [
                'order_type' => $orderType,
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
                'requested_limit' => $requestedLimit,
                'effective_limit' => $limit,
                'max_allowed' => self::MAX_FAILURES_EXPORT,
                'rows' => count($items),
            ],
        ];

        $this->logOpsApiCall(
            'ops-payments-failures-export',
            200,
            [
                'action' => 'export_failures_csv',
                'order_type' => $orderType,
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
                'requested_limit' => $requestedLimit,
                'effective_limit' => $limit,
            ],
            [
                'meta' => $result['meta'],
                'volume' => [
                    'rows' => (int) ($result['meta']['rows'] ?? 0),
                    'effective_limit' => (int) ($result['meta']['effective_limit'] ?? 0),
                ],
            ],
            null
        );

        return $result;
    }

    public function replayPaymentTransaction(string $paymentTransactionId): array
    {
        $paymentTx = PaymentTransaction::query()->find($paymentTransactionId);
        if (!$paymentTx) {
            return [
                'success' => false,
                'status' => 404,
                'business_code' => 'PAYMENT_TRANSACTION_NOT_FOUND',
                'message' => 'Transaction paiement introuvable',
            ];
        }

        if ((string) $paymentTx->status !== 'CONFIRMED') {
            return [
                'success' => false,
                'status' => 422,
                'business_code' => 'PAYMENT_NOT_CONFIRMED',
                'message' => 'Replay refusé: paiement non confirmé',
            ];
        }

        ExecuteReloadlyOrderJob::dispatch($paymentTx->id)->onQueue('reloadly');

        return [
            'success' => true,
            'status' => 202,
            'message' => 'Replay planifié',
            'data' => [
                'payment_transaction_id' => $paymentTx->id,
                'status' => $paymentTx->status,
            ],
        ];
    }

    public function replayFailedOrdersBatch(array $filters = []): array
    {
        $type = strtoupper((string) ($filters['type'] ?? 'ALL'));
        $dryRun = (bool) ($filters['dry_run'] ?? false);
        $requireConfirm = (bool) ($filters['require_confirm'] ?? false);
        $requestedLimit = max(1, (int) ($filters['limit'] ?? 25));
        $limit = min($requestedLimit, self::MAX_BATCH_REPLAY);
        $from = !empty($filters['from']) ? now()->parse((string) $filters['from']) : null;
        $to = !empty($filters['to']) ? now()->parse((string) $filters['to']) : null;

        if (!in_array($type, ['ALL', 'AIRTIME', 'DATA'], true)) {
            $result = [
                'success' => false,
                'status' => 422,
                'business_code' => 'INVALID_BATCH_TYPE',
                'message' => 'Type batch invalide',
            ];

            $this->logOpsApiCall(
                'ops-payments-replay-batch',
                (int) $result['status'],
                [
                    'action' => 'replay_batch',
                    'type' => $type,
                    'dry_run' => $dryRun,
                    'require_confirm' => $requireConfirm,
                    'requested_limit' => $requestedLimit,
                    'effective_limit' => $limit,
                ],
                [
                    'business_code' => $result['business_code'],
                    'volume' => ['selected_count' => 0, 'dispatched_count' => 0],
                ],
                $result['message']
            );

            return $result;
        }

        if (!$dryRun && !$requireConfirm) {
            $result = [
                'success' => false,
                'status' => 422,
                'business_code' => 'BATCH_CONFIRMATION_REQUIRED',
                'message' => 'Confirmation explicite requise pour exécuter le replay batch',
            ];

            $this->logOpsApiCall(
                'ops-payments-replay-batch',
                (int) $result['status'],
                [
                    'action' => 'replay_batch',
                    'type' => $type,
                    'dry_run' => $dryRun,
                    'require_confirm' => $requireConfirm,
                    'requested_limit' => $requestedLimit,
                    'effective_limit' => $limit,
                ],
                [
                    'business_code' => $result['business_code'],
                    'volume' => ['selected_count' => 0, 'dispatched_count' => 0],
                ],
                $result['message']
            );

            return $result;
        }

        $targets = collect();

        if (in_array($type, ['ALL', 'AIRTIME'], true)) {
            $targets = $targets->concat(
                AirtimeOrder::query()
                    ->where('status', 'FAILED')
                    ->whereNotNull('payment_transaction_id')
                    ->whereHas('paymentTransaction', fn ($q) => $q->where('status', 'CONFIRMED'))
                    ->when($from, fn ($q) => $q->where('updated_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('updated_at', '<=', $to))
                    ->latest('updated_at')
                    ->limit($limit)
                    ->get()
                    ->map(fn (AirtimeOrder $o) => [
                        'order_type' => 'AIRTIME',
                        'order_id' => $o->id,
                        'payment_transaction_id' => $o->payment_transaction_id,
                        'updated_at' => optional($o->updated_at)->toIso8601String(),
                    ])
            );
        }

        if (in_array($type, ['ALL', 'DATA'], true)) {
            $targets = $targets->concat(
                DataOrder::query()
                    ->where('status', 'FAILED')
                    ->whereNotNull('payment_transaction_id')
                    ->whereHas('paymentTransaction', fn ($q) => $q->where('status', 'CONFIRMED'))
                    ->when($from, fn ($q) => $q->where('updated_at', '>=', $from))
                    ->when($to, fn ($q) => $q->where('updated_at', '<=', $to))
                    ->latest('updated_at')
                    ->limit($limit)
                    ->get()
                    ->map(fn (DataOrder $o) => [
                        'order_type' => 'DATA',
                        'order_id' => $o->id,
                        'payment_transaction_id' => $o->payment_transaction_id,
                        'updated_at' => optional($o->updated_at)->toIso8601String(),
                    ])
            );
        }

        $selected = $targets
            ->sortByDesc('updated_at')
            ->unique('payment_transaction_id')
            ->take($limit)
            ->values();

        if (!$dryRun) {
            foreach ($selected as $target) {
                ExecuteReloadlyOrderJob::dispatch($target['payment_transaction_id'])->onQueue('reloadly');
            }
        }

        $result = [
            'success' => true,
            'status' => 202,
            'message' => $dryRun ? 'Dry-run batch terminé' : 'Replay batch planifié',
            'data' => [
                'dry_run' => $dryRun,
                'require_confirm' => $requireConfirm,
                'requested_limit' => $requestedLimit,
                'effective_limit' => $limit,
                'max_allowed' => self::MAX_BATCH_REPLAY,
                'selected_count' => $selected->count(),
                'dispatched_count' => $dryRun ? 0 : $selected->count(),
                'type' => $type,
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
                'items' => $selected->all(),
            ],
        ];

        $this->logOpsApiCall(
            'ops-payments-replay-batch',
            (int) $result['status'],
            [
                'action' => 'replay_batch',
                'type' => $type,
                'dry_run' => $dryRun,
                'require_confirm' => $requireConfirm,
                'requested_limit' => $requestedLimit,
                'effective_limit' => $limit,
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
            [
                'message' => $result['message'],
                'volume' => [
                    'selected_count' => $result['data']['selected_count'] ?? 0,
                    'dispatched_count' => $result['data']['dispatched_count'] ?? 0,
                ],
            ],
            null
        );

        return $result;
    }

    private function collectFailedOrders(string $orderType, $from, $to)
    {
        $airtime = AirtimeOrder::query()
            ->where('status', 'FAILED')
            ->when($from, fn ($q) => $q->where('updated_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('updated_at', '<=', $to))
            ->get()
            ->map(fn (AirtimeOrder $o) => [
                'order_type' => 'AIRTIME',
                'order_id' => $o->id,
                'payment_transaction_id' => $o->payment_transaction_id,
                'error_code' => $o->error_code,
                'error_message' => $o->error_message,
                'updated_at' => optional($o->updated_at)->toIso8601String(),
            ]);

        $data = DataOrder::query()
            ->where('status', 'FAILED')
            ->when($from, fn ($q) => $q->where('updated_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('updated_at', '<=', $to))
            ->get()
            ->map(fn (DataOrder $o) => [
                'order_type' => 'DATA',
                'order_id' => $o->id,
                'payment_transaction_id' => $o->payment_transaction_id,
                'error_code' => $o->error_code,
                'error_message' => $o->error_message,
                'updated_at' => optional($o->updated_at)->toIso8601String(),
            ]);

        return match ($orderType) {
            'AIRTIME' => $airtime->sortByDesc('updated_at')->values(),
            'DATA' => $data->sortByDesc('updated_at')->values(),
            default => $airtime->concat($data)->sortByDesc('updated_at')->values(),
        };
    }

    private function logOpsApiCall(
        string $service,
        int $status,
        array $requestPayload,
        array $responsePayload,
        ?string $errorMessage
    ): void {
        try {
            ApiLog::create([
                'service' => $service,
                'endpoint' => request()->path(),
                'method' => request()->method(),
                'status_code' => $status,
                'duration_ms' => null,
                'correlation_id' => (string) (
                    request()->attributes->get('correlation_id')
                    ?? request()->header('X-Correlation-ID')
                    ?? Str::uuid()->toString()
                ),
                'idempotency_key' => (string) request()->header('X-Idempotency-Key', ''),
                'request_ip' => request()->ip(),
                'request_headers' => request()->headers->all(),
                'request_body' => $requestPayload,
                'response_body' => $responsePayload,
                'error_message' => $errorMessage,
                'user_id' => request()->user()?->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to persist ops api_logs entry', ['error' => $e->getMessage()]);
        }
    }

    private function maskSensitiveData(mixed $value, ?string $key = null): mixed
    {
        if ($this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $masked = [];
            foreach ($value as $childKey => $childValue) {
                $masked[$childKey] = $this->maskSensitiveData($childValue, (string) $childKey);
            }

            return $masked;
        }

        return $value;
    }

    private function isSensitiveKey(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function maskIdempotencyKey(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $visible = min(8, strlen($value));
        return substr($value, 0, $visible) . '****';
    }
}
