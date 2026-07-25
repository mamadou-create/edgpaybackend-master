<?php

namespace App\Console\Commands;

use App\Services\AiraloPackagesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshAiraloCatalogCache extends Command
{
    protected $signature = 'airalo:catalog:refresh {--no-warm : Invalide le cache sans le recharger immédiatement}';

    protected $description = 'Invalide le cache du catalogue Airalo pour forcer un rechargement depuis l’API Live';

    public function handle(AiraloPackagesService $packagesService): int
    {
        $packagesService->invalidateCatalogCache();
        $this->info('Cache du catalogue Airalo invalidé.');

        if (!$this->option('no-warm')) {
            $countries = $packagesService->getCountries();
            $this->info(sprintf('%d destinations Airalo rechargées depuis l’API Live.', count($countries)));
            Log::info('Airalo catalog synchronized.', ['destinations_count' => count($countries)]);
        }

        return self::SUCCESS;
    }
}