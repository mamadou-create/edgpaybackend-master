<?php

namespace App\Jobs;

use App\Events\ReloadlyOrderProcessed;
use App\Interfaces\ReloadlyServiceInterface;
use App\Models\AirtimeOrder;
use App\Models\DataOrder;
use App\Models\PaymentTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExecuteReloadlyOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(private string $paymentTransactionId)
    {
        $this->onQueue('reloadly');
    }

    public function handle(ReloadlyServiceInterface $reloadlyService): void
    {
        /** @var PaymentTransaction|null $paymentTx */
        $paymentTx = PaymentTransaction::with(['transaction', 'airtimeOrders', 'dataOrders'])
            ->find($this->paymentTransactionId);

        if (!$paymentTx) {
            Log::warning('ExecuteReloadlyOrderJob payment transaction not found', [
                'payment_transaction_id' => $this->paymentTransactionId,
            ]);
            return;
        }

        if ($paymentTx->status !== 'CONFIRMED') {
            Log::info('ExecuteReloadlyOrderJob skipped because payment is not confirmed', [
                'payment_transaction_id' => $paymentTx->id,
                'status' => $paymentTx->status,
            ]);
            return;
        }

        $airtimeOrder = $paymentTx->airtimeOrders->first();
        if ($airtimeOrder instanceof AirtimeOrder) {
            $this->processAirtimeOrder($paymentTx, $airtimeOrder, $reloadlyService);
            return;
        }

        $dataOrder = $paymentTx->dataOrders->first();
        if ($dataOrder instanceof DataOrder) {
            $this->processDataOrder($paymentTx, $dataOrder, $reloadlyService);
            return;
        }

        Log::warning('ExecuteReloadlyOrderJob no order found for payment transaction', [
            'payment_transaction_id' => $paymentTx->id,
        ]);
    }

    private function processAirtimeOrder(
        PaymentTransaction $paymentTx,
        AirtimeOrder $order,
        ReloadlyServiceInterface $reloadlyService
    ): void {
        $eventToDispatch = null;

        if ($order->status === 'SUCCESS') {
            return;
        }

        DB::transaction(function () use ($paymentTx, $order, $reloadlyService, &$eventToDispatch) {
            $order->refresh();
            if ($order->status === 'SUCCESS') {
                return;
            }

            $order->status = 'PROCESSING';
            $order->save();

            $payload = [
                'operatorId' => (int) $order->operator_id,
                'amount' => (float) $order->amount,
                'useLocalAmount' => true,
                'customIdentifier' => (string) ($order->metadata['custom_identifier'] ?? $paymentTx->payment_reference),
                'recipientPhone' => [
                    'countryCode' => (string) $order->country_code,
                    'number' => (string) $order->recipient_msisdn,
                ],
            ];

            $result = $reloadlyService->topupAirtime($payload);

            if ($result['success']) {
                $responseData = $result['data'] ?? [];
                $order->status = 'SUCCESS';
                $order->reloadly_transaction_id = (string) ($responseData['transactionId'] ?? $responseData['id'] ?? $order->reloadly_transaction_id);
                $order->error_code = null;
                $order->error_message = null;
                $order->delivered_at = now();
                $order->metadata = array_merge($order->metadata ?? [], ['reloadly_response' => $responseData]);
                $order->save();

                if ($paymentTx->transaction) {
                    $paymentTx->transaction->status = 'SUCCESS';
                    $paymentTx->transaction->processed_at = now();
                    $paymentTx->transaction->save();
                }

                $eventToDispatch = new ReloadlyOrderProcessed(
                    paymentTransactionId: $paymentTx->id,
                    orderType: 'AIRTIME',
                    orderId: $order->id,
                    status: 'SUCCESS',
                    reloadlyTransactionId: $order->reloadly_transaction_id
                );

                return;
            }

            $code = (string) ($result['business_code'] ?? 'RELOADLY_TOPUP_FAILED');
            $message = (string) ($result['message'] ?? 'Recharge airtime échouée');

            $order->status = 'FAILED';
            $order->error_code = $code;
            $order->error_message = $message;
            $order->metadata = array_merge($order->metadata ?? [], ['reloadly_error' => $result['data'] ?? []]);
            $order->save();

            if ($this->shouldRetry($result)) {
                $order->status = 'PENDING';
                $order->save();
                throw new \RuntimeException($message);
            }

            $eventToDispatch = new ReloadlyOrderProcessed(
                paymentTransactionId: $paymentTx->id,
                orderType: 'AIRTIME',
                orderId: $order->id,
                status: 'FAILED',
                errorCode: $code,
                errorMessage: $message
            );
        }, 3);

        if ($eventToDispatch instanceof ReloadlyOrderProcessed) {
            event($eventToDispatch);
        }
    }

    private function processDataOrder(
        PaymentTransaction $paymentTx,
        DataOrder $order,
        ReloadlyServiceInterface $reloadlyService
    ): void {
        $eventToDispatch = null;

        if ($order->status === 'SUCCESS') {
            return;
        }

        DB::transaction(function () use ($paymentTx, $order, $reloadlyService, &$eventToDispatch) {
            $order->refresh();
            if ($order->status === 'SUCCESS') {
                return;
            }

            $order->status = 'PROCESSING';
            $order->save();

            $payload = [
                'operatorId' => (int) $order->operator_id,
                'amount' => (float) $order->amount,
                'useLocalAmount' => true,
                'customIdentifier' => (string) ($order->metadata['custom_identifier'] ?? $paymentTx->payment_reference),
                'bundleId' => (string) $order->data_plan_id,
                'recipientPhone' => [
                    'countryCode' => (string) $order->country_code,
                    'number' => (string) $order->recipient_msisdn,
                ],
            ];

            $result = $reloadlyService->topupData($payload);

            if ($result['success']) {
                $responseData = $result['data'] ?? [];
                $order->status = 'SUCCESS';
                $order->reloadly_transaction_id = (string) ($responseData['transactionId'] ?? $responseData['id'] ?? $order->reloadly_transaction_id);
                $order->error_code = null;
                $order->error_message = null;
                $order->delivered_at = now();
                $order->metadata = array_merge($order->metadata ?? [], ['reloadly_response' => $responseData]);
                $order->save();

                if ($paymentTx->transaction) {
                    $paymentTx->transaction->status = 'SUCCESS';
                    $paymentTx->transaction->processed_at = now();
                    $paymentTx->transaction->save();
                }

                $eventToDispatch = new ReloadlyOrderProcessed(
                    paymentTransactionId: $paymentTx->id,
                    orderType: 'DATA',
                    orderId: $order->id,
                    status: 'SUCCESS',
                    reloadlyTransactionId: $order->reloadly_transaction_id
                );

                return;
            }

            $code = (string) ($result['business_code'] ?? 'RELOADLY_DATA_TOPUP_FAILED');
            $message = (string) ($result['message'] ?? 'Recharge data échouée');

            $order->status = 'FAILED';
            $order->error_code = $code;
            $order->error_message = $message;
            $order->metadata = array_merge($order->metadata ?? [], ['reloadly_error' => $result['data'] ?? []]);
            $order->save();

            if ($this->shouldRetry($result)) {
                $order->status = 'PENDING';
                $order->save();
                throw new \RuntimeException($message);
            }

            $eventToDispatch = new ReloadlyOrderProcessed(
                paymentTransactionId: $paymentTx->id,
                orderType: 'DATA',
                orderId: $order->id,
                status: 'FAILED',
                errorCode: $code,
                errorMessage: $message
            );
        }, 3);

        if ($eventToDispatch instanceof ReloadlyOrderProcessed) {
            event($eventToDispatch);
        }
    }

    private function shouldRetry(array $result): bool
    {
        $status = (int) ($result['status'] ?? 500);
        $businessCode = (string) ($result['business_code'] ?? '');

        if ($status >= 500) {
            return true;
        }

        return in_array($businessCode, [
            'RELOADLY_NETWORK_ERROR',
            'RELOADLY_PROVIDER_ERROR',
            'SERVICE_UNAVAILABLE',
            'GATEWAY_TIMEOUT',
        ], true);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExecuteReloadlyOrderJob failed permanently', [
            'payment_transaction_id' => $this->paymentTransactionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
