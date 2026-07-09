<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = App\Models\ApiLog::query()
    ->orderByDesc('created_at')
    ->limit(12)
    ->get([
        'id',
        'service',
        'endpoint',
        'method',
        'status_code',
        'duration_ms',
        'error_message',
        'request_body',
        'response_body',
        'created_at',
    ]);

$out = $rows->map(function ($r) {
    return [
        'id' => $r->id,
        'service' => $r->service,
        'endpoint' => $r->endpoint,
        'method' => $r->method,
        'status_code' => $r->status_code,
        'duration_ms' => $r->duration_ms,
        'error_message' => $r->error_message,
        'request_body' => $r->request_body,
        'response_body' => $r->response_body,
        'created_at' => (string) $r->created_at,
    ];
});

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
