<?php

namespace Tests\Feature\Reloadly;

use App\Models\CurrencyConversionRate;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentCurrencyResolver;
use App\Services\UtilityPaymentIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentCurrencyResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uses_a_wallet_with_the_same_currency_without_conversion(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user, 'XOF', 20000);

        $resolution = app(PaymentCurrencyResolver::class)->resolve($user, 10000, 'XOF', 'XOF');

        expect($resolution['success'])->toBeTrue();
        expect($resolution['data']['wallet_id'])->toBe($wallet->id);
        expect($resolution['data']['wallet_amount'])->toBe(10000.0);
        expect($resolution['data']['conversion_applied'])->toBeFalse();
    }

    #[Test]
    public function it_reserves_the_converted_wallet_amount_when_an_enabled_rate_exists(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user, 'GNF', 200000);
        CurrencyConversionRate::create([
            'wallet_currency' => 'GNF',
            'payment_currency' => 'XOF',
            'rate' => 12.3,
            'enabled' => true,
            'status' => CurrencyConversionRate::STATUS_ACTIVE,
            'effective_from' => now()->subMinute(),
        ]);

        $resolution = app(PaymentCurrencyResolver::class)->resolve($user, 10000, 'XOF', 'GNF');
        $intent = app(UtilityPaymentIntentService::class)->reserve(
            $user,
            10000,
            'XOF',
            'SUBSCRIBER-123',
            $resolution['data'],
        );

        expect($resolution['success'])->toBeTrue();
        expect($intent->payment_currency)->toBe('XOF');
        expect($intent->wallet_currency)->toBe('GNF');
        expect((float) $intent->conversion_rate)->toBe(12.3);
        expect((float) $intent->converted_amount)->toBe(123000.0);
        expect($intent->conversion_applied)->toBeTrue();
        expect($wallet->fresh()->blocked_amount)->toBe(123000);
    }

    #[Test]
    public function it_rejects_an_unsupported_payment_currency_without_reserving(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user, 'GNF', 200000);

        $resolution = app(PaymentCurrencyResolver::class)->resolve($user, 10000, 'XOF', 'GNF');

        expect($resolution['success'])->toBeFalse();
        expect($resolution['code'])->toBe('PAYMENT_CURRENCY_NOT_SUPPORTED');
        expect($wallet->fresh()->blocked_amount)->toBe(0);
        expect(PaymentIntent::query()->count())->toBe(0);
    }

    #[Test]
    public function it_rejects_an_expired_conversion_rate_without_reserving(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user, 'GNF', 200000);
        CurrencyConversionRate::create([
            'wallet_currency' => 'GNF',
            'payment_currency' => 'XOF',
            'rate' => 12.3,
            'enabled' => true,
            'status' => CurrencyConversionRate::STATUS_ACTIVE,
            'effective_from' => now()->subDay(),
            'effective_to' => now()->subMinute(),
        ]);

        $resolution = app(PaymentCurrencyResolver::class)->resolve($user, 10000, 'XOF', 'GNF');

        expect($resolution['success'])->toBeFalse();
        expect($resolution['code'])->toBe('PAYMENT_CURRENCY_NOT_SUPPORTED');
        expect($wallet->fresh()->blocked_amount)->toBe(0);
        expect(PaymentIntent::query()->count())->toBe(0);
    }

    #[Test]
    public function it_allows_only_one_converted_reservation_when_the_wallet_covers_one_payment(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user, 'GNF', 123000);
        CurrencyConversionRate::create([
            'wallet_currency' => 'GNF',
            'payment_currency' => 'XOF',
            'rate' => 12.3,
            'enabled' => true,
            'status' => CurrencyConversionRate::STATUS_ACTIVE,
            'effective_from' => now()->subMinute(),
        ]);
        $resolver = app(PaymentCurrencyResolver::class);
        $resolution = $resolver->resolve($user, 10000, 'XOF', 'GNF');
        app(UtilityPaymentIntentService::class)->reserve($user, 10000, 'XOF', 'SUBSCRIBER-ONE', $resolution['data']);

        $second = $resolver->resolve($user->fresh(), 10000, 'XOF', 'GNF');

        expect($second['success'])->toBeFalse();
        expect($second['code'])->toBe('PAYMENT_CURRENCY_INSUFFICIENT_FUNDS');
        expect($wallet->fresh()->blocked_amount)->toBe(123000);
        expect(PaymentIntent::query()->count())->toBe(1);
    }

    private function wallet(User $user, string $currency, int $cashAvailable): Wallet
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