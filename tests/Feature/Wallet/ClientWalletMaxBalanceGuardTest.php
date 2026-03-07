<?php

namespace Tests\Feature\Wallet;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientWalletMaxBalanceGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function generic_wallet_deposit_is_blocked_when_client_balance_would_exceed_cap(): void
    {
        [$superAdminRole, $clientRole] = $this->createRoles();

        $admin = User::factory()->create([
            'role_id' => $superAdminRole->id,
            'is_pro' => false,
        ]);

        $client = User::factory()->create([
            'role_id' => $clientRole->id,
            'is_pro' => false,
            'solde_portefeuille' => 990,
        ]);

        Wallet::create([
            'user_id' => $admin->id,
            'currency' => 'GNF',
            'cash_available' => 100000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        $clientWallet = Wallet::create([
            'user_id' => $client->id,
            'currency' => 'GNF',
            'cash_available' => 990,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        SystemSetting::updateOrCreate(
            ['key' => 'max_client_wallet_balance'],
            [
                'value' => '1000',
                'type' => 'integer',
                'group' => 'limits',
                'description' => 'Solde maximum autorisé pour un wallet client',
                'is_active' => true,
                'is_editable' => true,
                'order' => 21,
            ]
        );

        $this->actingAs($admin, 'api');

        $response = $this->postJson("/api/v1/wallets/{$clientWallet->id}/deposit", [
            'amount' => 20,
            'user_id' => $client->id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.max_client_wallet_balance', 1000)
            ->assertJsonPath('errors.client_current_balance', 990)
            ->assertJsonPath('errors.client_projected_balance', 1010);

        $clientWallet->refresh();
        $this->assertSame(990, (int) $clientWallet->cash_available);
    }

    #[Test]
    public function client_cannot_update_system_settings(): void
    {
        [$superAdminRole, $clientRole] = $this->createRoles();

        User::factory()->create([
            'role_id' => $superAdminRole->id,
            'is_pro' => false,
        ]);

        $client = User::factory()->create([
            'role_id' => $clientRole->id,
            'is_pro' => false,
        ]);

        SystemSetting::updateOrCreate(
            ['key' => 'max_client_wallet_balance'],
            [
                'value' => '1000000000',
                'type' => 'integer',
                'group' => 'limits',
                'description' => 'Solde maximum autorisé pour un wallet client',
                'is_active' => true,
                'is_editable' => true,
                'order' => 21,
            ]
        );

        $this->actingAs($client, 'api');

        $response = $this->putJson('/api/v1/system-settings/key/max_client_wallet_balance', [
            'value' => '500',
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertSame(
            '1000000000',
            (string) SystemSetting::where('key', 'max_client_wallet_balance')->value('value')
        );
    }

    private function createRoles(): array
    {
        $superAdminRole = Role::query()->updateOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Rôle super admin (tests)',
                'is_super_admin' => true,
            ]
        );

        $clientRole = Role::query()->updateOrCreate(
            ['slug' => 'client'],
            [
                'name' => 'Client',
                'description' => 'Rôle client (tests)',
                'is_super_admin' => false,
            ]
        );

        return [$superAdminRole, $clientRole];
    }
}
