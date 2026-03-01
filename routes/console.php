<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:schedule', function () {
    $this->call('payments:process');
})->describe('Exécute la commande de traitement des paiements chaque minute.');

// Rapport journalier envoyé chaque jour à 00:05
Schedule::command('report:daily')->dailyAt('00:05');

// ─── Module Crédit PRO ──────────────────────────────────────────────────────

// Détection retards : chaque jour à 01:00
Schedule::command('credit:detecter-retards')->dailyAt('01:00');

// Recalcul scores : chaque jour à 02:00
Schedule::command('credit:recalcul-scores')->dailyAt('02:00');
