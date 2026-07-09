<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rate = (int) config('troc.reference_to_gnf_rate', 8700);

echo str_pad('Marque / Modele (Annee)', 42) . "Prix marche GNF\n";
echo str_repeat('-', 60) . "\n";

foreach (\App\Models\TrocCarPrice::orderBy('base_price')->get() as $r) {
    $gnf = number_format(round($r->base_price * $rate), 0, '.', ' ');
    echo str_pad("{$r->brand} {$r->model} ({$r->year})", 42) . "{$gnf} GNF\n";
}
