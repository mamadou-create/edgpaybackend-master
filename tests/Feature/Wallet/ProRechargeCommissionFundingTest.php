<?php

namespace Tests\Feature\Wallet;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProRechargeCommissionFundingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_debits_super_admin_wallet_to_fund_pro_deposit_commission(): void
    {
        [$superAdminRole, $proRole, $clientRole] = $this->createRoles();

        $superAdmin = User::factory()->create([
            'role_id' => $superAdminRole->id,
            'is_pro' => false,
            'solde_portefeuille' => 50000,
        ]);
        $pro = User::factory()->create([
            'role_id' => $proRole->id,
            'is_pro' => true,
            'solde_portefeuille' => 200000,
            'commission_portefeuille' => 0,
        ]);
        $client = User::factory()->create([
            'role_id' => $clientRole->id,
            'is_pro' => false,
            'solde_portefeuille' => 0,
        ]);

        $superWallet = Wallet::create([
            'user_id' => $superAdmin->id,
            'currency' => 'GNF',
            'cash_available' => 50000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $proWallet = Wallet::create([
            'user_id' => $pro->id,
            'currency' => 'GNF',
            'cash_available' => 200000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $clientWallet = Wallet::create([
            'user_id' => $client->id,
            'currency' => 'GNF',
            'cash_available' => 0,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        SystemSetting::updateOrCreate(
            ['key' => 'pro_gain_percent_on_client_deposit'],
            [
                'value' => '10',
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de gain du PRO sur chaque dépôt/recharge client',
                'is_active' => true,
                'is_editable' => true,
                'order' => 31,
            ]
        );

        $this->actingAs($pro, 'api');

        $response = $this->postJson('/api/v1/pro/wallet/recharge-client', [
            'amount' => 10000,
            'recipient_phone' => $client->phone,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pro_commission_credited', 1000)
            ->assertJsonPath('data.pro_gain_percent', 10);

        $superWallet->refresh();
        $proWallet->refresh();
        $clientWallet->refresh();
        $pro->refresh();

        $this->assertSame(49000, (int) $superWallet->cash_available);
        $this->assertSame(190000, (int) $proWallet->cash_available);
        $this->assertSame(10000, (int) $clientWallet->cash_available);
        $this->assertSame(1000, (int) $proWallet->commission_available);
        $this->assertSame(1000, (int) $proWallet->commission_balance);
        $this->assertSame(1000, (int) $pro->commission_portefeuille);
    }

    #[Test]
    public function it_rolls_back_recharge_when_super_admin_cannot_fund_deposit_commission(): void
    {
        [$superAdminRole, $proRole, $clientRole] = $this->createRoles();

        $superAdmin = User::factory()->create([
            'role_id' => $superAdminRole->id,
            'is_pro' => false,
            'solde_portefeuille' => 0,
        ]);
        $pro = User::factory()->create([
            'role_id' => $proRole->id,
            'is_pro' => true,
            'solde_portefeuille' => 200000,
            'commission_portefeuille' => 0,
        ]);
        $client = User::factory()->create([
            'role_id' => $clientRole->id,
            'is_pro' => false,
            'solde_portefeuille' => 0,
        ]);

        $superWallet = Wallet::create([
            'user_id' => $superAdmin->id,
            'currency' => 'GNF',
            'cash_available' => 0,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $proWallet = Wallet::create([
            'user_id' => $pro->id,
            'currency' => 'GNF',
            'cash_available' => 200000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $clientWallet = Wallet::create([
            'user_id' => $client->id,
            'currency' => 'GNF',
            'cash_available' => 0,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        SystemSetting::updateOrCreate(
            ['key' => 'pro_gain_percent_on_client_deposit'],
            [
                'value' => '10',
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de gain du PRO sur chaque dépôt/recharge client',
                'is_active' => true,
                'is_editable' => true,
                'order' => 31,
            ]
        );

        $this->actingAs($pro, 'api');

        $response = $this->postJson('/api/v1/pro/wallet/recharge-client', [
            'amount' => 10000,
            'recipient_phone' => $client->phone,
        ]);

        $response
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $superWallet->refresh();
        $proWallet->refresh();
        $clientWallet->refresh();
        $pro->refresh();

        $this->assertSame(0, (int) $superWallet->cash_available);
        $this->assertSame(200000, (int) $proWallet->cash_available);
        $this->assertSame(0, (int) $clientWallet->cash_available);
        $this->assertSame(0, (int) $proWallet->commission_available);
        $this->assertSame(0, (int) $proWallet->commission_balance);
        $this->assertSame(0, (int) $pro->commission_portefeuille);
    }

    #[Test]
    public function it_debits_client_cashout_fee_and_reverses_part_to_admin_wallet(): void
    {
        [$superAdminRole, $proRole, $clientRole] = $this->createRoles();

        $superAdmin = User::factory()->create([
            'role_id' => $superAdminRole->id,
            'is_pro' => false,
            'solde_portefeuille' => 0,
            'commission_portefeuille' => 0,
        ]);
        $pro = User::factory()->create([
            'role_id' => $proRole->id,
            'is_pro' => true,
            'solde_portefeuille' => 0,
            'commission_portefeuille' => 0,
        ]);
        $client = User::factory()->create([
            'role_id' => $clientRole->id,
            'is_pro' => false,
            'solde_portefeuille' => 200000,
            'commission_portefeuille' => 0,
        ]);

        $superWallet = Wallet::create([
            'user_id' => $superAdmin->id,
            'currency' => 'GNF',
            'cash_available' => 0,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $proWallet = Wallet::create([
            'user_id' => $pro->id,
            'currency' => 'GNF',
            'cash_available' => 0,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);
        $clientWallet = Wallet::create([
            'user_id' => $client->id,
            'currency' => 'GNF',
            'cash_available' => 200000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        SystemSetting::updateOrCreate(
            ['key' => 'client_cashout_fee_percent'],
            [
                'value' => '10',
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de frais prélevé sur le client lors d\'un retrait cash',
                'is_active' => true,
                'is_editable' => true,
                'order' => 32,
            ]
        );

        SystemSetting::updateOrCreate(
            ['key' => 'pro_gain_percent_on_client_cashout'],
            [
                'value' => '2',
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de gain du PRO sur chaque retrait cash client',
                'is_active' => true,
                'is_editable' => true,
                'order' => 30,
            ]
        );

        $this->actingAs($client, 'api');

        $response = $this->postJson('/api/v1/client/wallet/cashout-pro', [
            'amount' => 10000,
            'pro_phone' => $pro->phone,
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cashout_fee_percent', 10)
            ->assertJsonPath('data.cashout_fee_amount', 1000)
            ->assertJsonPath('data.total_debited', 11000)
            ->assertJsonPath('data.pro_commission_credited', 200)
            ->assertJsonPath('data.admin_fee_retained', 800);

        $superWallet->refresh();
        $proWallet->refresh();
        $clientWallet->refresh();
        $client->refresh();
        $pro->refresh();
        $superAdmin->refresh();

        $this->assertSame(189000, (int) $clientWallet->cash_available);
        $this->assertSame(10000, (int) $proWallet->cash_available);
        $this->assertSame(800, (int) $superWallet->cash_available);
        $this->assertSame(200, (int) $proWallet->commission_available);
        $this->assertSame(200, (int) $proWallet->commission_balance);
        $this->assertSame(200, (int) $pro->commission_portefeuille);
        $this->assertSame(800, (int) $superAdmin->solde_portefeuille);
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

        $proRole = Role::query()->updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro',
                'description' => 'Rôle pro (tests)',
                'is_super_admin' => false,
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

        return [$superAdminRole, $proRole, $clientRole];
    }
}
