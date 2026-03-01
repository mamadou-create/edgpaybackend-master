<?php

namespace App\Providers;

use App\Services\NimbaSmsService;
use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(NimbaSmsService::class, function ($app) {
            return new NimbaSmsService();
        });
    }

    public function boot()
    {
        //
    }
}