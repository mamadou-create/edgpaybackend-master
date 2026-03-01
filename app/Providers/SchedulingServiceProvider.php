<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;


class SchedulingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */

    public function boot()
    {
        $schedule = app(Schedule::class);

          // Planification 4 fois par jour : minuit, 08h, 14h, 20h
        $schedule->command('payments:process')
                 ->cron('0 0,8,14,20 * * *')
                 ->appendOutputTo(storage_path('logs/payments_scheduler.log'));;
    }
}
