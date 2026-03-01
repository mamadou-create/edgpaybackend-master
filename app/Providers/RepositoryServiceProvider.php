<?php

namespace App\Providers;

use App\Interfaces\AnnouncementRepositoryInterface;
use App\Interfaces\ApiClientRepositoryInterface;
use App\Interfaces\CommissionRepositoryInterface;
use App\Interfaces\CompteurRepositoryInterface;
use Illuminate\Support\ServiceProvider;
use App\Repositories\DemandeProRepository;
use App\Repositories\UserRepository;
use App\Interfaces\UserRepositoryInterface;
use App\Interfaces\DemandeProRepositoryInterface;
use App\Interfaces\MessageRepositoryInterface;
use App\Interfaces\PaymentRepositoryInterface;
use App\Interfaces\SystemSettingRepositoryInterface;
use App\Interfaces\TopupRequestRepositoryInterface;
use App\Interfaces\WalletRepositoryInterface;
use App\Interfaces\WalletTransactionRepositoryInterface;
use App\Interfaces\WithdrawalRequestRepositoryInterface;
use App\Repositories\AnnouncementRepository;
use App\Repositories\ApiClientRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\CompteurRepository;
use App\Repositories\MessageRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\SystemSettingRepository;
use App\Repositories\TopupRequestRepository;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;
use App\Repositories\WithdrawalRequestRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(DemandeProRepositoryInterface::class, DemandeProRepository::class);
        $this->app->bind(WalletRepositoryInterface::class, WalletRepository::class);
        $this->app->bind(WalletTransactionRepositoryInterface::class, WalletTransactionRepository::class);
        $this->app->bind(
            TopupRequestRepositoryInterface::class,
            TopupRequestRepository::class
        );
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->bind(CompteurRepositoryInterface::class, CompteurRepository::class);
        $this->app->bind(WithdrawalRequestRepositoryInterface::class, WithdrawalRequestRepository::class);


        $this->app->bind(ApiClientRepositoryInterface::class, ApiClientRepository::class);

        $this->app->bind(
            SystemSettingRepositoryInterface::class,
            SystemSettingRepository::class
        );

        $this->app->bind(MessageRepositoryInterface::class, MessageRepository::class);

        $this->app->bind(
            AnnouncementRepositoryInterface::class,
            AnnouncementRepository::class
        );

         $this->app->bind(
            CommissionRepositoryInterface::class,
            CommissionRepository::class
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
