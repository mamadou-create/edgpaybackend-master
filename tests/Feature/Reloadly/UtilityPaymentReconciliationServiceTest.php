<?php

namespace Tests\Feature\Reloadly;

use App\Enums\ReloadlyProduct;
use App\Models\PaymentIntent;
use App\Models\ReloadlyUtilityTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\UtilityPaymentIntentService;
use App\Services\UtilityPaymentReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UtilityPaymentReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_confirms_a_successful_provider_transaction(): void
    {
        [$intent, $wallet] = $this->pendingIntent('PROCESSING', 734);
        Http::fake(fn (Request $request) => Http::response([
            'transaction' => $this->providerTransaction($intent, 'SUCCESSFUL', 734),
        ]));

        $outcome = app(UtilityPaymentReconciliationService::class)->reconcile($intent->id);

        expect($outcome)->toBe('SUCCESS');
        expect($intent->fresh()->status)->toBe('SUCCESS');
        expect($wallet->fresh()->cash_available)->toBe(40000);
        expect($wallet->fresh()->blocked_amount)->toBe(0);
        expect($intent->fresh()->provider_response['billDetails'])->not->toHaveKey('subscriberDetails');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transactions/734'));
    }

    #[Test]
    public function it_releases_a_failed_provider_transaction(): void
    {
        [$intent, $wallet] = $this->pendingIntent('TIMEOUT', 735);
        Http::fake(fn () => Http::response([
            'transaction' => $this->providerTransaction($intent, 'FAILED', 735),
        ]));

        $outcome = app(UtilityPaymentReconciliationService::class)->reconcile($intent->id);

        expect($outcome)->toBe('FAILED');
        expect($intent->fresh()->status)->toBe('FAILED');
        expect($wallet->fresh()->cash_available)->toBe(50000);
        expect($wallet->fresh()->blocked_amount)->toBe(0);
    }

    #[Test]
    public function it_keeps_the_reservation_when_the_provider_is_still_processing(): void
    {
        [$intent, $wallet] = $this->pendingIntent('PROCESSING', 736);
        Http::fake(fn () => Http::response([
            'transaction' => $this->providerTransaction($intent, 'PROCESSING', 736),
        ]));

        $outcome = app(UtilityPaymentReconciliationService::class)->reconcile($intent->id);

        expect($outcome)->toBe('PROCESSING');
        expect($intent->fresh()->status)->toBe('PROCESSING');
        expect($wallet->fresh()->cash_available)->toBe(50000);
        expect($wallet->fresh()->blocked_amount)->toBe(10000);
    }

    #[Test]
    public function it_keeps_the_reservation_when_a_transaction_is_not_found_by_reference(): void
    {
        [$intent, $wallet] = $this->pendingIntent('TIMEOUT');
        Http::fake(fn () => Http::response(['content' => []]));

        $outcome = app(UtilityPaymentReconciliationService::class)->reconcile($intent->id);

        expect($outcome)->toBe('NOT_FOUND');
        expect($intent->fresh()->status)->toBe('TIMEOUT');
        expect($wallet->fresh()->blocked_amount)->toBe(10000);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/transactions?referenceId='));
    }

    #[Test]
    public function it_keeps_the_reservation_when_reloadly_returns_an_api_error(): void
    {
        [$intent, $wallet] = $this->pendingIntent('PROCESSING', 737);
        Http::fake(fn () => Http::response(['errorCode' => 'SERVICE_UNAVAILABLE'], 503));

        $outcome = app(UtilityPaymentReconciliationService::class)->reconcile($intent->id);

        expect($outcome)->toBe('PROVIDER_ERROR');
        expect($intent->fresh()->status)->toBe('PROCESSING');
        expect($wallet->fresh()->blocked_amount)->toBe(10000);
    }

    private function pendingIntent(string $status, ?int $reloadlyTransactionId = null): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'XOF',
            'cash_available' => 50000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $intent = app(UtilityPaymentIntentService::class)->reserve($user, 10000, 'XOF', 'SUBSCRIBER-123');
        $intent->update(['status' => $status]);
        ReloadlyUtilityTransaction::create([
            'idempotency_key' => hash('sha256', $intent->id),
            'user_id' => $user->id,
            'reloadly_transaction_id' => $reloadlyTransactionId,
            'reference_id' => $intent->provider_reference,
            'biller_id' => 27,
            'subscriber_account_number' => hash('sha256', 'SUBSCRIBER-123'),
            'amount' => 10000,
            'use_local_amount' => true,
            'api_status' => $status,
        ]);
        Cache::put(ReloadlyProduct::UTILITIES->tokenCacheKey(), 'test-utilities-token', 300);

        return [$intent, $wallet];
    }

    private function providerTransaction(PaymentIntent $intent, string $status, int $id): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'referenceId' => $intent->provider_reference,
            'message' => $status === 'FAILED' ? 'Paiement refusé' : 'Paiement en cours',
            'billDetails' => [
                'subscriberDetails' => ['accountNumber' => 'SUBSCRIBER-123'],
            ],
        ];
    }
}