<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$paymentReference = $argv[1] ?? '';
if ($paymentReference === '') {
    fwrite(STDERR, "Usage: php tools/payment_confirmation_diagnose.php <payment_reference>\n");
    exit(1);
}

$tx = App\Models\PaymentTransaction::with(['transaction'])->where('payment_reference', $paymentReference)->first();
if (!$tx) {
    echo json_encode(['found' => false, 'payment_reference' => $paymentReference], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$webhooks = App\Models\WebhookLog::query()
    ->where('payment_transaction_id', $tx->id)
    ->orWhere(function ($q) use ($paymentReference) {
        $q->whereJsonContains('payload->payment_reference', $paymentReference)
          ->orWhereJsonContains('payload->reference', $paymentReference)
          ->orWhereJsonContains('payload->merchantReference', $paymentReference);
    })
    ->orderByDesc('created_at')
    ->limit(5)
    ->get(['id','provider','event_id','signature_valid','status','processing_error','created_at','processed_at','payload']);

$out = [
    'found' => true,
    'payment_reference' => $tx->payment_reference,
    'payment_transaction_id' => $tx->id,
    'payment_status' => $tx->status,
    'confirmation_status' => $tx->confirmation_status,
    'webhook_verified' => $tx->webhook_verified,
    'webhook_verified_at' => (string) $tx->webhook_verified_at,
    'paid_at' => (string) $tx->paid_at,
    'provider_payment_id' => $tx->provider_payment_id,
    'raw_response' => $tx->raw_response,
    'transaction_status' => $tx->transaction?->status,
    'webhook_logs_count' => $webhooks->count(),
    'webhook_logs' => $webhooks,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
