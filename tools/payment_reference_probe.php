<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$paymentReference = $argv[1] ?? '';
if ($paymentReference === '') {
    fwrite(STDERR, "Usage: php tools/payment_reference_probe.php <payment_reference>\n");
    exit(1);
}

$tx = App\Models\PaymentTransaction::with(['transaction', 'airtimeOrders', 'dataOrders'])
    ->where('payment_reference', $paymentReference)
    ->orWhere('merchant_reference', $paymentReference)
    ->orWhere('provider_payment_id', $paymentReference)
    ->first();

if (!$tx) {
    echo json_encode([
        'found' => false,
        'payment_reference' => $paymentReference,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$airtime = $tx->airtimeOrders->first();
$data = $tx->dataOrders->first();

echo json_encode([
    'found' => true,
    'payment_transaction_id' => $tx->id,
    'payment_reference' => $tx->payment_reference,
    'merchant_reference' => $tx->merchant_reference,
    'provider_payment_id' => $tx->provider_payment_id,
    'provider' => $tx->provider,
    'payment_status' => $tx->status,
    'confirmation_status' => $tx->confirmation_status,
    'transaction_status' => $tx->transaction?->status,
    'airtime_order_status' => $airtime?->status,
    'data_order_status' => $data?->status,
    'reloadly_transaction_id' => $airtime?->reloadly_transaction_id ?? $data?->reloadly_transaction_id,
    'updated_at' => (string) $tx->updated_at,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
