<?php

/**
 * Debug helper: explains why a credit account is blocked.
 *
 * Usage:
 *   php tools/debug_credit_block.php <phone_or_user_id>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$needle = $argv[1] ?? null;
if (!is_string($needle) || trim($needle) === '') {
    fwrite(STDERR, "Usage: php tools/debug_credit_block.php <phone_or_user_id>\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** @var \App\Models\User|null $user */
$user = \App\Models\User::query()
    ->where('id', $needle)
    ->orWhere('phone', $needle)
    ->orWhere('phone', 'like', '%' . $needle . '%')
    ->with('creditProfile')
    ->first();

if (!$user) {
    echo "USER_NOT_FOUND needle={$needle}\n";
    exit(0);
}

echo "USER id={$user->id} phone={$user->phone} email={$user->email} is_pro={$user->is_pro} status={$user->status}\n";

$cp = $user->creditProfile;
if (!$cp) {
    echo "CREDIT_PROFILE: none\n";
    exit(0);
}

echo "CREDIT_PROFILE est_bloque=" . (int) $cp->est_bloque . " bloque_jusqu_au={$cp->bloque_jusqu_au} score={$cp->score_fiabilite} risque={$cp->niveau_risque}\n";
echo "CREDIT_PROFILE motif_blocage=" . ($cp->motif_blocage ?? 'null') . "\n";
echo "CREDIT_PROFILE credit_limite={$cp->credit_limite} credit_disponible={$cp->credit_disponible} total_encours={$cp->total_encours}\n";

$crit = \App\Models\AnomalyFlag::query()
    ->where('user_id', $user->id)
    ->where('niveau', 'critique')
    ->where('resolved', false)
    ->orderByDesc('created_at')
    ->get(['id', 'type_anomalie', 'description', 'created_at']);

echo "CRITIQUES_NON_RESOLUES count=" . $crit->count() . "\n";
foreach ($crit as $f) {
    echo "- {$f->id} | {$f->type_anomalie} | {$f->description} | {$f->created_at}\n";
}
