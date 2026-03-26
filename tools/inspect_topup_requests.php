<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$phone = $argv[1] ?? null;

if (!$phone) {
    fwrite(STDERR, "Usage: php tools/inspect_topup_requests.php <phone>\n");
    exit(1);
}

$user = App\Models\User::query()
    ->where('phone', $phone)
    ->first(['id', 'phone', 'email', 'display_name', 'assigned_user', 'role_id']);

if (!$user) {
    echo json_encode(['found' => false, 'phone' => $phone], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$topups = App\Models\TopupRequest::query()
    ->where('pro_id', $user->id)
    ->latest('created_at')
    ->limit(20)
    ->get([
        'id',
        'pro_id',
        'amount',
        'status',
        'balance_target',
        'statut_paiement',
        'created_at',
        'idempotency_key',
        'note',
    ]);

echo json_encode([
    'found' => true,
    'user' => $user,
    'topups_count' => $topups->count(),
    'topups' => $topups,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;