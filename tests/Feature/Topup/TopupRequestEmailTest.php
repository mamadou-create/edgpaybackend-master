<?php

namespace Tests\Feature\Topup;

use App\Helpers\HelperStatus;
use App\Mail\TopupRequestSubmittedMail;
use App\Models\Creance;
use App\Models\CreditProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TopupRequestEmailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function demande_recharge_envoie_un_email_a_un_admin_ayant_permission_validate_pending_sans_config_env(): void
    {
        Mail::fake();

        // Pas de recipients configurés, on teste le fallback.
        config(['edgpay.topup.request_notify_emails' => []]);

        $perm = Permission::query()->updateOrCreate(
            ['slug' => 'transactions.validate_pending'],
            [
                'name' => 'Valider transactions en attente',
                'module' => 'Transactions',
                'description' => 'Permission tests',
            ]
        );

        $role = Role::query()->create([
            'name' => 'Topup Admin (tests)',
            'slug' => 'topup_admin_test',
            'description' => 'Rôle test',
            'is_super_admin' => false,
        ]);
        $role->permissions()->attach($perm->id, ['access_level' => 'oui']);

        $admin = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'diallob84.md@gmail.com',
            'is_pro' => false,
        ]);

        $pro = User::factory()->create(['is_pro' => true]);

        $this->actingAs($pro);
        $res = $this->postJson('/api/v1/topup-requests', [
            'pro_id' => $pro->id,
            'amount' => 1000,
            'note' => 'test recharge',
        ]);

        $res->assertStatus(201);

        Mail::assertSent(TopupRequestSubmittedMail::class, function ($mailable) use ($admin) {
            return $mailable->hasTo($admin->email);
        });
    }

    #[Test]
    public function un_sous_admin_ne_recoit_pas_les_emails_d_un_pro_non_assigne(): void
    {
        Mail::fake();

        $perm = Permission::query()->updateOrCreate(
            ['slug' => 'transactions.validate_pending'],
            [
                'name' => 'Valider transactions en attente',
                'module' => 'Transactions',
                'description' => 'Permission tests',
            ]
        );

        // Super-admin destinataire global
        $superRole = Role::query()->updateOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Rôle super admin (tests)',
                'is_super_admin' => true,
            ]
        );
        $superAdmin = User::factory()->create([
            'role_id' => $superRole->id,
            'email' => 'superadmin@example.test',
            'is_pro' => false,
        ]);

        // Sous-admin (finance_admin) avec la permission, mais ne doit pas recevoir si PRO non assigné
        $financeRole = Role::query()->updateOrCreate(
            ['slug' => 'finance_admin'],
            [
                'name' => 'Sous-Admin Finance',
                'description' => 'Rôle finance (tests)',
                'is_super_admin' => false,
            ]
        );
        $financeRole->permissions()->syncWithoutDetaching([$perm->id => ['access_level' => 'oui']]);
        $financeAdmin = User::factory()->create([
            'role_id' => $financeRole->id,
            'email' => 'finance@example.test',
            'is_pro' => false,
        ]);

        $pro = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => null,
        ]);

        $this->actingAs($pro);
        $res = $this->postJson('/api/v1/topup-requests', [
            'pro_id' => $pro->id,
            'amount' => 1000,
            'note' => 'test recharge',
        ]);

        $res->assertStatus(201);

        Mail::assertSent(TopupRequestSubmittedMail::class, function ($mailable) use ($superAdmin, $financeAdmin) {
            return $mailable->hasTo($superAdmin->email) && ! $mailable->hasTo($financeAdmin->email);
        });
    }

    #[Test]
    public function un_pro_assigne_notifie_uniquement_le_sous_admin_assigne_et_pas_le_super_admin(): void
    {
        Mail::fake();

        $perm = Permission::query()->updateOrCreate(
            ['slug' => 'transactions.validate_pending'],
            [
                'name' => 'Valider transactions en attente',
                'module' => 'Transactions',
                'description' => 'Permission tests',
            ]
        );

        $superRole = Role::query()->updateOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Rôle super admin (tests)',
                'is_super_admin' => true,
            ]
        );
        $superAdmin = User::factory()->create([
            'role_id' => $superRole->id,
            'email' => 'superadmin@example.test',
            'is_pro' => false,
        ]);

        $financeRole = Role::query()->updateOrCreate(
            ['slug' => 'finance_admin'],
            [
                'name' => 'Sous-Admin Finance',
                'description' => 'Rôle finance (tests)',
                'is_super_admin' => false,
            ]
        );
        $financeRole->permissions()->syncWithoutDetaching([$perm->id => ['access_level' => 'oui']]);

        $financeAdmin = User::factory()->create([
            'role_id' => $financeRole->id,
            'email' => 'finance@example.test',
            'is_pro' => false,
        ]);

        $pro = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => $financeAdmin->id,
        ]);

        $this->actingAs($pro);
        $res = $this->postJson('/api/v1/topup-requests', [
            'pro_id' => $pro->id,
            'amount' => 1000,
            'note' => 'test recharge assignee',
        ]);

        $res->assertStatus(201);

        Mail::assertSent(TopupRequestSubmittedMail::class, function ($mailable) use ($financeAdmin, $superAdmin) {
            return $mailable->hasTo($financeAdmin->email)
                && ! $mailable->hasTo($superAdmin->email);
        });
    }

    #[Test]
    public function approbation_impayee_refusee_si_montant_depasse_limite_credit_pro(): void
    {
        $superRole = Role::query()->updateOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Rôle super admin (tests)',
                'is_super_admin' => true,
            ]
        );

        $admin = User::factory()->create([
            'role_id' => $superRole->id,
            'is_pro' => false,
        ]);

        $pro = User::factory()->create(['is_pro' => true]);

        Wallet::create([
            'user_id' => $admin->id,
            'currency' => 'GNF',
            'cash_available' => 1_000_000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        Wallet::create([
            'user_id' => $pro->id,
            'currency' => 'GNF',
            'cash_available' => 0,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        CreditProfile::updateOrCreate(
            ['user_id' => $pro->id],
            [
                'credit_limite' => 10_000,
                'credit_disponible' => 10_000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        $topup = TopupRequest::create([
            'pro_id' => $pro->id,
            'amount' => 50_000,
            'kind' => 'CASH',
            'status' => HelperStatus::PENDING,
            'idempotency_key' => 'topup-limit-test-' . uniqid(),
            'note' => 'Test dépassement limite',
        ]);

        $this->actingAs($admin);
        $res = $this->postJson('/api/v1/topup-requests/' . $topup->id . '/approve', [
            'statut_paiement' => 'impaye',
            'note' => 'Approbation test',
        ]);

        $res
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $topup->refresh();
        $this->assertEquals(HelperStatus::PENDING, $topup->status);

        $this->assertDatabaseMissing('creances', [
            'commande_id' => $topup->id,
        ]);

        $this->assertNull(Creance::query()->where('commande_id', $topup->id)->first());
    }

    #[Test]
    public function sous_admin_ne_peut_pas_approuver_un_impaye_au_dessus_de_la_limite_credit(): void
    {
        $subAdminRole = Role::query()->updateOrCreate(
            ['slug' => 'finance_admin'],
            [
                'name' => 'Sous-Admin Finance',
                'description' => 'Rôle sous-admin (tests)',
                'is_super_admin' => false,
            ]
        );

        $superRole = Role::query()->updateOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Rôle super admin (tests)',
                'is_super_admin' => true,
            ]
        );

        $superAdmin = User::factory()->create([
            'role_id' => $superRole->id,
            'is_pro' => false,
        ]);

        $subAdmin = User::factory()->create([
            'role_id' => $subAdminRole->id,
            'is_pro' => false,
        ]);

        $proRole = Role::query()->updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'PRO',
                'description' => 'Rôle pro (tests)',
                'is_super_admin' => false,
            ]
        );

        $pro = User::factory()->create([
            'role_id' => $proRole->id,
            'is_pro' => true,
            'assigned_user' => $subAdmin->id,
        ]);

        Wallet::create([
            'user_id' => $subAdmin->id,
            'currency' => 'GNF',
            'cash_available' => 1_000_000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        Wallet::create([
            'user_id' => $superAdmin->id,
            'currency' => 'GNF',
            'cash_available' => 1_000_000,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        Wallet::create([
            'user_id' => $pro->id,
            'currency' => 'GNF',
            'cash_available' => 0,
            'blocked_amount' => 0,
            'commission_available' => 0,
            'commission_balance' => 0,
        ]);

        CreditProfile::updateOrCreate(
            ['user_id' => $pro->id],
            [
                'credit_limite' => 10_000,
                'credit_disponible' => 10_000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        $topup = TopupRequest::create([
            'pro_id' => $pro->id,
            'amount' => 50_000,
            'kind' => 'CASH',
            'status' => HelperStatus::PENDING,
            'idempotency_key' => 'topup-limit-subadmin-test-' . uniqid(),
            'note' => 'Test dépassement limite sous-admin',
        ]);

        $this->actingAs($subAdmin);
        $res = $this->postJson('/api/v1/topup-requests/' . $topup->id . '/approve', [
            'statut_paiement' => 'impaye',
            'note' => 'Approbation test sous-admin',
        ]);

        $res
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $topup->refresh();
        $this->assertEquals(HelperStatus::PENDING, $topup->status);

        $this->assertDatabaseMissing('creances', [
            'commande_id' => $topup->id,
        ]);

        $this->assertNull(Creance::query()->where('commande_id', $topup->id)->first());
    }

}
