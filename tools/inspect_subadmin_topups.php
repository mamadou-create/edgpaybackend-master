<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$subAdminId = $argv[1] ?? null;

if (!$subAdminId) {
    fwrite(STDERR, "Usage: php tools/inspect_subadmin_topups.php <sub_admin_id>\n");
    exit(1);
}

$topups = App\Models\TopupRequest::query()
    ->with(['pro:id,display_name,phone,assigned_user'])
    ->whereHas('pro', function ($query) use ($subAdminId) {
        $query->where('assigned_user', $subAdminId);
    })
    ->latest('created_at')
    ->limit(20)
    ->get([
        'id',
        'pro_id',
        'amount',
        'status',
        'balance_target',
        'created_at',
        'idempotency_key',
    ]);

echo json_encode([
    'sub_admin_id' => $subAdminId,
    'count' => $topups->count(),
    'topups' => $topups,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;