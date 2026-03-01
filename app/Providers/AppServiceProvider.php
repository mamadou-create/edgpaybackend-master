<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ─── Services Module Crédit ─────────────────────────────────────────────
        $this->app->singleton(\App\Services\AuditLogService::class);
        $this->app->singleton(\App\Services\FinancialLedgerService::class);

        $this->app->singleton(\App\Services\AnomalyDetectionService::class, function ($app) {
            return new \App\Services\AnomalyDetectionService(
                $app->make(\App\Services\AuditLogService::class)
            );
        });

        $this->app->singleton(\App\Services\RiskScoringService::class, function ($app) {
            return new \App\Services\RiskScoringService(
                $app->make(\App\Services\AuditLogService::class)
            );
        });

        $this->app->singleton(\App\Services\CreanceService::class, function ($app) {
            return new \App\Services\CreanceService(
                $app->make(\App\Services\FinancialLedgerService::class),
                $app->make(\App\Services\RiskScoringService::class),
                $app->make(\App\Services\AnomalyDetectionService::class),
                $app->make(\App\Services\AuditLogService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Auto-créer un profil de crédit pour chaque nouvel utilisateur
        User::observe(UserObserver::class);

        if (App::environment('production')) {
            URL::forceScheme('https');
        }

       // $this->registerPolicies();

        // Gates pour les permissions
        Gate::define('has-permission', function (User $user, $permission) {
            return $user->hasPermission($permission);
        });

        Gate::define('has-limited-permission', function (User $user, $permission) {
            return $user->hasLimitedPermission($permission);
        });

        Gate::define('is-super-admin', function (User $user) {
            return $user->isSuperAdmin();
        });

        Gate::define('is-sub-admin', function (User $user) {
            return $user->isSubAdmin();
        });

        Gate::define('is-pro', function (User $user) {
            return $user->isPro();
        });
    }
}
