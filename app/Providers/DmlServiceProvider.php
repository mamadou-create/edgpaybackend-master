<?php

namespace App\Providers;

use App\Interfaces\DmlRepositoryInterface;
use App\Interfaces\DmlServiceInterface;
use App\Repositories\DmlRepository;
use App\Services\DmlService;
use Illuminate\Support\ServiceProvider;

class DmlServiceProvider extends ServiceProvider
{
    /**
     * Enregistrer les services DML
     */
    public function register(): void
    {
        // Repository
        $this->app->bind(DmlRepositoryInterface::class, DmlRepository::class);

        // Service
        $this->app->bind(DmlServiceInterface::class, DmlService::class);
    }

    /**
     * Bootstrap les services DML
     */
    public function boot(): void
    {
        // Configuration supplémentaire si nécessaire
    }
}