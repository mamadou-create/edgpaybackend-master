<?php

namespace Tests\Feature\Reloadly;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdempotencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_replays_the_saved_response_and_rejects_a_changed_request(): void
    {
        $user = User::factory()->create();
        $callCount = 0;
        Route::post('/api/test/idempotency-replay', function () use (&$callCount) {
            $callCount++;

            return response()->json([
                'success' => true,
                'data' => ['call_count' => $callCount],
            ], 201);
        })->middleware('idempotency:test-replay,900');

        $headers = ['X-Idempotency-Key' => 'utility-replay-key-001'];
        $this->actingAs($user, 'api');

        $first = $this->withHeaders($headers)->postJson('/api/test/idempotency-replay', ['amount' => 1000, 'billerId' => 27]);
        $second = $this->withHeaders($headers)->postJson('/api/test/idempotency-replay', ['billerId' => 27, 'amount' => 1000]);
        $changed = $this->withHeaders($headers)->postJson('/api/test/idempotency-replay', ['amount' => 2000, 'billerId' => 27]);

        $first->assertCreated()->assertExactJson(['success' => true, 'data' => ['call_count' => 1]]);
        $second->assertCreated()->assertExactJson(['success' => true, 'data' => ['call_count' => 1]]);
        $changed
            ->assertConflict()
            ->assertJsonPath('error', 'IDEMPOTENCY_KEY_REUSED')
            ->assertJsonPath('business_code', 'IDEMPOTENCY_KEY_REUSED');
        expect($callCount)->toBe(1);
    }
}