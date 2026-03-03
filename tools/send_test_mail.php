<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$to = $argv[1] ?? (string) config('mail.from.address');

fwrite(STDOUT, "TO: {$to}\n");

try {
    Illuminate\Support\Facades\Mail::raw('EDGPAY test ' . date('c'), function ($message) use ($to): void {
        $message->to($to)->subject('EDGPAY test');
    });

    fwrite(STDOUT, "SENT\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
