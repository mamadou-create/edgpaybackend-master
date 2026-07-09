<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$jobs = Illuminate\Support\Facades\DB::table('jobs')
    ->where('queue', 'reloadly')
    ->orderBy('id')
    ->get(['id', 'queue', 'attempts', 'available_at', 'created_at', 'payload']);

$out = [];
foreach ($jobs as $job) {
    $payloadText = (string) $job->payload;
    $payloadJson = json_decode($payloadText, true);
    $commandText = is_array($payloadJson) ? (string) (($payloadJson['data']['command'] ?? '')) : '';

    $paymentTxId = null;
    if ($commandText !== '' && preg_match('/"paymentTransactionId";s:\\d+:"([a-f0-9-]{36})"/i', $commandText, $m)) {
        $paymentTxId = $m[1];
    } elseif ($commandText !== '' && preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $commandText, $m2)) {
        $paymentTxId = $m2[1];
    }

    $out[] = [
        'id' => $job->id,
        'queue' => $job->queue,
        'attempts' => $job->attempts,
        'available_at' => $job->available_at,
        'created_at' => $job->created_at,
        'payment_transaction_id' => $paymentTxId,
        'payload_preview' => substr($payloadText, 0, 220),
        'command_preview' => substr($commandText, 0, 220),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
