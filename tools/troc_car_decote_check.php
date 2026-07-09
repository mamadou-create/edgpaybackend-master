<?php
/**
 * Simule computeDeductions + applyPricingPolicy pour valider les décotes.
 */

$rate = 8700; // GNF par unité de référence

function pct(float $base, float $percent): float {
    return round($base * $percent / 100, 2);
}

function computeDeductions(float $basePrice, int $mileageKm, string $condition, array $details): array {
    $p = fn(float $pct) => pct($basePrice, $pct);
    $items = [];
    $total = 0.0;

    if ($mileageKm > 200_000)       { $items[] = ['Kilométrage >200k (-25%)', $p(25)]; $total += $p(25); }
    elseif ($mileageKm > 150_000)   { $items[] = ['Kilométrage >150k (-15%)', $p(15)]; $total += $p(15); }
    elseif ($mileageKm > 100_000)   { $items[] = ['Kilométrage >100k  (-8%)', $p( 8)]; $total += $p( 8); }

    if ($condition === 'scratched')  { $items[] = ['Carrosserie rayée   (-5%)', $p( 5)]; $total += $p( 5); }
    if ($condition === 'broken')     { $items[] = ['État dégradé       (-20%)', $p(20)]; $total += $p(20); }

    if (($details['engine_condition'] ?? 'good') === 'damaged')   { $items[] = ['Moteur HS          (-20%)', $p(20)]; $total += $p(20); }
    if (($details['gearbox_condition'] ?? 'good') === 'damaged')  { $items[] = ['Boîte HS           (-12%)', $p(12)]; $total += $p(12); }
    if (($details['body_condition'] ?? 'good') === 'cracked')     { $items[] = ['Carrosserie cracked(-10%)', $p(10)]; $total += $p(10); }
    if (($details['interior_condition'] ?? 'good') === 'worn')    { $items[] = ['Intérieur usé       (-5%)', $p( 5)]; $total += $p( 5); }
    if (($details['air_conditioning_ok'] ?? true) === false)      { $items[] = ['Clim HS             (-5%)', $p( 5)]; $total += $p( 5); }
    if (($details['accident_history'] ?? false) === true)         { $items[] = ['Accident           (-15%)', $p(15)]; $total += $p(15); }

    return ['items' => $items, 'total' => $total];
}

function applyPricingPolicy(float $base, float $deductionTotal): array {
    $resale = $base;
    $opCost = round($resale * 0.05, 2);
    $margin = round(max($resale * 0.10, 8.0), 2);
    $maxBuyback = max(0.0, round($resale - $deductionTotal - $opCost - $margin, 2));
    $rawOffer = max(0.0, round($base - $deductionTotal, 2));
    $policyOffer = min($rawOffer, $maxBuyback);
    $floor = round($base * 0.35, 2);
    $estimated = max(0.0, round(max($policyOffer, min($floor, $maxBuyback)), 2));
    return ['estimated' => $estimated, 'floor_gnf' => round($floor * 8700)];
}

function show(string $label, float $base, int $mileage, string $condition, array $details): void {
    global $rate;
    $d = computeDeductions($base, $mileage, $condition, $details);
    $p = applyPricingPolicy($base, $d['total']);
    $baseGnf = number_format(round($base * $rate), 0, '.', ' ');
    $estGnf  = number_format(round($p['estimated'] * $rate), 0, '.', ' ');
    $dedGnf  = number_format(round($d['total'] * $rate), 0, '.', ' ');
    $pctDed  = $base > 0 ? round($d['total'] / $base * 100, 1) : 0;
    echo "\n┌─ $label\n";
    echo "│  Prix marché  : $baseGnf GNF\n";
    echo "│  Décotes totales : $dedGnf GNF ($pctDed% du prix)\n";
    echo "│  Offre de reprise: $estGnf GNF\n";
    foreach ($d['items'] as [$l, $a]) {
        echo "│    - $l → " . number_format(round($a * $rate), 0, '.', ' ') . " GNF\n";
    }
}

// ─── Prix de référence calculés depuis GNF ──────────────────────
$corolla2015  = round(70_000_000  / 8700, 2);  // ~8046
$lc200_2016   = round(420_000_000 / 8700, 2);  // ~48276
$patrol2016   = round(280_000_000 / 8700, 2);  // ~32184

$parfait   = [];
$moyen     = ['engine_condition' => 'good', 'gearbox_condition' => 'good', 'air_conditioning_ok' => false, 'accident_history' => false];
$mauvais   = ['engine_condition' => 'damaged', 'gearbox_condition' => 'damaged', 'body_condition' => 'cracked', 'interior_condition' => 'worn', 'air_conditioning_ok' => false, 'accident_history' => true];

echo "═══ SIMULATION DES DÉCOTES — Parc guinéen ═══\n";

show("Corolla 2015 — parfait (20 000 km)",         $corolla2015, 20_000,  'good',     $parfait);
show("Corolla 2015 — moyen  (130 000 km, clim HS)",  $corolla2015, 130_000, 'good',     $moyen);
show("Corolla 2015 — mauvais (210 000 km, tout HS)", $corolla2015, 210_000, 'broken',   $mauvais);

show("Land Cruiser 200 2016 — parfait (40 000 km)", $lc200_2016, 40_000,  'good',     $parfait);
show("Land Cruiser 200 2016 — moyen  (160 000 km)", $lc200_2016, 160_000, 'good',     $moyen);
show("Land Cruiser 200 2016 — mauvais (230 000 km)",$lc200_2016, 230_000, 'broken',   $mauvais);

show("Nissan Patrol 2016 — parfait (30 000 km)",    $patrol2016, 30_000,  'good',     $parfait);
show("Nissan Patrol 2016 — accident + moteur HS",   $patrol2016, 180_000, 'scratched',$mauvais);

echo "\n";
