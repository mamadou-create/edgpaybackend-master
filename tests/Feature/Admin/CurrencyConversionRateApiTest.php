<?php

namespace Tests\Feature\Admin;

use App\Models\CurrencyConversionRate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CurrencyConversionRateApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_a_super_admin_can_manage_currency_conversion_rates(): void
    {
        $user = User::factory()->create();
        $rate = CurrencyConversionRate::create([
            'wallet_currency' => 'GNF',
            'payment_currency' => 'XOF',
            'rate' => 12.3,
            'status' => CurrencyConversionRate::STATUS_DRAFT,
            'enabled' => false,
            'effective_from' => now(),
        ]);
        $this->actingAs($user, 'api');

        $this->getJson('/api/v1/admin/currency-conversion-rates')->assertForbidden();
        $this->withHeaders(['X-Idempotency-Key' => 'currency-rate-client-create'])
            ->postJson('/api/v1/admin/currency-conversion-rates', $this->payload())
            ->assertForbidden();
        $this->withHeaders(['X-Idempotency-Key' => 'currency-rate-client-approve'])
            ->postJson("/api/v1/admin/currency-conversion-rates/{$rate->id}/approve")
            ->assertForbidden();
        $this->withHeaders(['X-Idempotency-Key' => 'currency-rate-client-activate'])
            ->postJson("/api/v1/admin/currency-conversion-rates/{$rate->id}/activate")
            ->assertForbidden();
        $this->withHeaders(['X-Idempotency-Key' => 'currency-rate-client-deactivate'])
            ->postJson("/api/v1/admin/currency-conversion-rates/{$rate->id}/deactivate")
            ->assertForbidden();
        $this->getJson("/api/v1/admin/currency-conversion-rates/{$rate->id}/history")->assertForbidden();
    }

    #[Test]
    public function a_super_admin_can_create_approve_activate_and_deactivate_a_rate_with_audit_history(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'api');

        $created = $this->withHeaders(['X-Idempotency-Key' => 'currency-rate-create-001'])
            ->postJson('/api/v1/admin/currency-conversion-rates', $this->payload());
        $created->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', CurrencyConversionRate::STATUS_DRAFT)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.created_by', $admin->id);
        $rateId = $created->json('data.id');

        $approved = $this->withHeaders(['X-Idempotency-Key' => 'currency-rate-approve-001'])
            ->postJson("/api/v1/admin/currency-conversion-rates/{$rateId}/approve");
        $approved->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', CurrencyConversionRate::STATUS_APPROVED)
            ->assertJsonPath('data.approved_by', $admin->id)
            ->assertJsonPath('data.approved_at', fn ($value) => $value !== null);

        $activated = $this->withHeaders(['X-Idempotency-Key' => 'currency-rate-activate-001'])
            ->postJson("/api/v1/admin/currency-conversion-rates/{$rateId}/activate");
        $activated->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', CurrencyConversionRate::STATUS_ACTIVE)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.approved_by', $admin->id);
        $this->assertDatabaseHas('currency_conversion_rate_histories', ['currency_conversion_rate_id' => $rateId, 'action' => 'CREATED']);
        $this->assertDatabaseHas('currency_conversion_rate_histories', ['currency_conversion_rate_id' => $rateId, 'action' => 'APPROVED']);
        $this->assertDatabaseHas('currency_conversion_rate_histories', ['currency_conversion_rate_id' => $rateId, 'action' => 'ACTIVATED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'currency_rate_created', 'cible_id' => (string) $rateId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'currency_rate_approved', 'cible_id' => (string) $rateId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'currency_rate_activated', 'cible_id' => (string) $rateId]);

        $this->withHeaders(['X-Idempotency-Key' => 'currency-rate-deactivate-001'])
            ->postJson("/api/v1/admin/currency-conversion-rates/{$rateId}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', CurrencyConversionRate::STATUS_INACTIVE)
            ->assertJsonPath('data.enabled', false);
        $this->assertDatabaseHas('currency_conversion_rate_histories', ['currency_conversion_rate_id' => $rateId, 'action' => 'DEACTIVATED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'currency_rate_deactivated', 'cible_id' => (string) $rateId]);

        $this->getJson("/api/v1/admin/currency-conversion-rates/{$rateId}/history")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data');
    }

    #[Test]
    public function a_super_admin_cannot_activate_an_expired_rate(): void
    {
        $admin = $this->superAdmin();
        $rate = CurrencyConversionRate::create([
            'wallet_currency' => 'GNF',
            'payment_currency' => 'XOF',
            'rate' => 12.3,
            'status' => CurrencyConversionRate::STATUS_APPROVED,
            'enabled' => false,
            'approved_by' => $admin->id,
            'approved_at' => now()->subHour(),
            'effective_from' => now()->subDay(),
            'effective_to' => now()->subMinute(),
            'created_by' => $admin->id,
        ]);
        $this->actingAs($admin, 'api');

        $this->withHeaders(['X-Idempotency-Key' => 'currency-rate-expired-001'])
            ->postJson("/api/v1/admin/currency-conversion-rates/{$rate->id}/activate")
            ->assertUnprocessable()
            ->assertJsonPath('business_code', 'RATE_EXPIRED');
    }

    private function payload(): array
    {
        return [
            'from_currency' => 'XOF',
            'to_currency' => 'GNF',
            'rate' => 12.3,
            'effective_from' => now()->subMinute()->toIso8601String(),
        ];
    }

    private function superAdmin(): User
    {
        $role = Role::query()->create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'is_super_admin' => true,
        ]);

        $permissions = Permission::query()
            ->whereIn('slug', [
                'create_conversion_rate',
                'approve_conversion_rate',
                'activate_conversion_rate',
                'deactivate_conversion_rate',
                'view_conversion_audit',
            ])
            ->get();
        $role->permissions()->syncWithPivotValues($permissions->modelKeys(), ['access_level' => 'oui']);

        return User::factory()->create(['role_id' => $role->id]);
    }
}