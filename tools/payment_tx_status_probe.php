<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$paymentTxId = $argv[1] ?? '';
if ($paymentTxId === '') {
    fwrite(STDERR, "Usage: php tools/payment_tx_status_probe.php <payment_transaction_id>\n");
    exit(1);
}

$tx = App\Models\PaymentTransaction::with(['transaction', 'airtimeOrders', 'dataOrders'])
    ->find($paymentTxId);

if (!$tx) {
    echo json_encode([
        'found' => false,
        'payment_transaction_id' => $paymentTxId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$airtime = $tx->airtimeOrders->first();
$data = $tx->dataOrders->first();

echo json_encode([
    'found' => true,
    'payment_transaction_id' => $tx->id,
    'payment_reference' => $tx->payment_reference,
    'provider' => $tx->provider,
    'payment_status' => $tx->status,
    'confirmation_status' => $tx->confirmation_status,
    'transaction_status' => $tx->transaction?->status,
    'airtime_order' => $airtime ? [
        'id' => $airtime->id,
        'status' => $airtime->status,
        'reloadly_transaction_id' => $airtime->reloadly_transaction_id,
        'error_code' => $airtime->error_code,
        'error_message' => $airtime->error_message,
        'metadata' => $airtime->metadata,
    ] : null,
    'data_order' => $data ? [
        'id' => $data->id,
        'status' => $data->status,
        'reloadly_transaction_id' => $data->reloadly_transaction_id,
        'error_code' => $data->error_code,
        'error_message' => $data->error_message,
    ] : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
