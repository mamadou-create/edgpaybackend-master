<?php

namespace Tests\Feature\Reloadly;

use App\Models\User;
use App\Models\Wallet;
use App\Services\UtilityPaymentIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UtilityPaymentIntentServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reserves_and_confirms_only_the_authenticated_clients_wallet(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletFor($user, 50000, 'XOF');

        $intent = app(UtilityPaymentIntentService::class)->reserve(
            $user,
            10000,
            'XOF',
            'CANAL-ACCOUNT-123',
        );

        $wallet->refresh();
        expect($intent->status)->toBe('RESERVED');
        expect($wallet->cash_available)->toBe(50000);
        expect($wallet->blocked_amount)->toBe(10000);
        expect(DB::table('payment_intents')->where('id', $intent->id)->value('subscriber_account_number'))
            ->not->toContain('CANAL-ACCOUNT-123');

        app(UtilityPaymentIntentService::class)->confirm($intent, ['status' => 'SUCCESSFUL']);

        $wallet->refresh();
        expect($wallet->cash_available)->toBe(40000);
        expect($wallet->blocked_amount)->toBe(0);
    }

    #[Test]
    public function it_releases_a_failed_payment_without_debiting_the_client(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletFor($user, 50000, 'XOF');
        $intent = app(UtilityPaymentIntentService::class)->reserve($user, 10000, 'XOF', 'CANAL-ACCOUNT-123');

        app(UtilityPaymentIntentService::class)->release($intent, 'FAILED', ['status' => 'FAILED']);

        $wallet->refresh();
        expect($wallet->cash_available)->toBe(50000);
        expect($wallet->blocked_amount)->toBe(0);
        expect($intent->fresh()->status)->toBe('FAILED');
    }

    #[Test]
    public function it_rejects_currency_mismatch_before_reserving_money(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletFor($user, 50000, 'GNF');

        expect(fn () => app(UtilityPaymentIntentService::class)->reserve($user, 10000, 'XOF', 'CANAL-ACCOUNT-123'))
            ->toThrow('La devise du wallet client ne correspond pas à la devise Reloadly.');

        expect($wallet->fresh()->blocked_amount)->toBe(0);
    }

    #[Test]
    public function it_allows_only_one_reservation_when_the_wallet_covers_one_payment(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletFor($user, 10000, 'XOF');

        app(UtilityPaymentIntentService::class)->reserve($user, 10000, 'XOF', 'SUBSCRIBER-ONE');

        expect(fn () => app(UtilityPaymentIntentService::class)->reserve($user, 10000, 'XOF', 'SUBSCRIBER-TWO'))
            ->toThrow('Solde wallet insuffisant.');

        expect($wallet->fresh()->blocked_amount)->toBe(10000);
        expect(\App\Models\PaymentIntent::query()->where('user_id', $user->id)->count())->toBe(1);
    }

    private function walletFor(User $user, int $cashAvailable, string $currency): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'currency' => $currency,
            'cash_available' => $cashAvailable,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
    }
}