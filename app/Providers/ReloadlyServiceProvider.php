<?php

namespace App\Providers;

use App\Interfaces\ReloadlyGiftCardRepositoryInterface;
use App\Interfaces\ReloadlyRepositoryInterface;
use App\Interfaces\ReloadlyUtilityRepositoryInterface;
use App\Repositories\ReloadlyGiftCardRepository;
use App\Repositories\ReloadlyRepository;
use App\Repositories\ReloadlyUtilityRepository;
use Illuminate\Support\ServiceProvider;

class ReloadlyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReloadlyRepositoryInterface::class, ReloadlyRepository::class);
        $this->app->bind(ReloadlyGiftCardRepositoryInterface::class, ReloadlyGiftCardRepository::class);
        $this->app->bind(ReloadlyUtilityRepositoryInterface::class, ReloadlyUtilityRepository::class);
    }
}
