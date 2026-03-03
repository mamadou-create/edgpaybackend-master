<?php

namespace Tests\Feature\Topup;

use App\Mail\TopupRequestSubmittedMail;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TopupRequestEmailTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
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

    /** @test */
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
}
