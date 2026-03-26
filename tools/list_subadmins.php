<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = App\Models\User::query()
    ->with('role:id,slug,name,is_super_admin')
    ->whereHas('role', function ($query) {
        $query->where('is_super_admin', false)
            ->whereNotIn('slug', ['client', 'pro', 'api_client']);
    })
    ->orderBy('display_name')
    ->get(['id', 'display_name', 'phone', 'email', 'assigned_user', 'role_id']);

echo json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;