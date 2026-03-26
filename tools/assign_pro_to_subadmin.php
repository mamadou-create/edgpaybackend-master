<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$phone = $argv[1] ?? null;
$subAdminId = $argv[2] ?? null;

if (!$phone || !$subAdminId) {
    fwrite(STDERR, "Usage: php tools/assign_pro_to_subadmin.php <pro_phone> <sub_admin_id>\n");
    exit(1);
}

$user = App\Models\User::query()
    ->where('phone', $phone)
    ->first(['id', 'phone', 'email', 'display_name', 'assigned_user', 'role_id']);

if (!$user) {
    fwrite(STDERR, "PRO not found for phone {$phone}\n");
    exit(1);
}

$subAdmin = App\Models\User::query()
    ->with('role:id,slug,name,is_super_admin')
    ->where('id', $subAdminId)
    ->first(['id', 'display_name', 'phone', 'email', 'role_id']);

if (!$subAdmin) {
    fwrite(STDERR, "Sub-admin not found for id {$subAdminId}\n");
    exit(1);
}

$user->assigned_user = $subAdmin->id;
$user->save();

echo json_encode([
    'assigned' => true,
    'pro' => $user->fresh(['role:id,slug,name,is_super_admin']),
    'sub_admin' => $subAdmin,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;