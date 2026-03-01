<?php

namespace App\Providers;

use App\Interfaces\DjomyServiceInterface;
use App\Services\DjomyService;
use Illuminate\Support\ServiceProvider;

class DjomyServiceProvider extends ServiceProvider
{
    /**
     * Enregistrer les services Djomy
     */
    public function register(): void
    {

        // Service
         $this->app->bind(DjomyServiceInterface::class, DjomyService::class);
    }

    /**
     * Bootstrap les services Djomy
     */
    public function boot(): void
    {
        // Configuration supplémentaire si nécessaire
    }
}