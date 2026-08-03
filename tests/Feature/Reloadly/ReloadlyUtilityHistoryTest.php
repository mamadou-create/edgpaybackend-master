<?php

namespace Tests\Feature\Reloadly;

use App\Models\ReloadlyUtilityTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\UtilityPaymentIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReloadlyUtilityHistoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_a_typed_paginated_history_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'XOF',
            'cash_available' => 50000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $intent = app(UtilityPaymentIntentService::class)->reserve(
            $user,
            10000,
            'XOF',
            'SUBSCRIBER-123',
        );
        ReloadlyUtilityTransaction::create([
            'idempotency_key' => hash('sha256', 'utility-history-' . $user->id),
            'user_id' => $user->id,
            'reference_id' => $intent->provider_reference,
            'biller_id' => 27,
            'biller_name' => 'Canal+ Mali',
            'subscriber_account_number' => hash('sha256', 'SUBSCRIBER-123'),
            'amount' => 10000,
            'use_local_amount' => true,
            'api_status' => 'SUCCESS',
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/api/v1/reloadly/utilities/history?per_page=20');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.biller', 'Canal+ Mali')
            ->assertJsonPath('data.0.amount', 10000)
            ->assertJsonPath('data.0.currency', 'XOF')
            ->assertJsonPath('data.0.status', 'SUCCESS')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'biller',
                    'amount',
                    'currency',
                    'status',
                    'created_at',
                ]],
            ]);
    }
}