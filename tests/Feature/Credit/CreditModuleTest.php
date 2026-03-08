<?php

namespace Tests\Feature\Credit;

use App\Models\CreditProfile;
use App\Models\Creance;
use App\Models\CreanceTransaction;
use App\Models\LedgerEntry;
use App\Models\AnomalyFlag;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Jobs\SendCreanceReimbursementBatchSubmittedMailJob;
use App\Jobs\SendCreanceReimbursementSubmittedMailJob;
use App\Jobs\SendCreanceBatchValidatedReceiptMailJob;
use App\Jobs\SendCreanceValidatedReceiptMailJob;
use App\Jobs\SendCreanceRejectedMailJob;
use App\Jobs\SendCreanceBatchRejectedMailJob;
use App\Mail\CreanceReimbursementBatchSubmittedMail;
use App\Mail\CreanceReimbursementSubmittedMail;
use App\Services\AnomalyDetectionService;
use App\Services\AuditLogService;
use App\Services\CreanceService;
use App\Services\FinancialLedgerService;
use App\Services\RiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests d'intégration du module de crédit quasi-bancaire.
 *
 * Couvre :
 *  - Création créance avec vérification limite
 *  - Scoring et niveau risque
 *  - Validation paiement avec double sécurité
 *  - Immuabilité du ledger
 *  - Détection d'anomalies
 *  - Dashboard risk
 */
class CreditModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $clientPro;

    public function test_rejeter_paiement_batch_success(): void
    {
        Queue::fake();

        // Super admin role pour bypass check-permission:credits.manage
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

        $client = User::factory()->create(['is_pro' => true]);
        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 500000,
                'credit_disponible' => 500000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        // Créer 2 créances impayées
        $this->creanceService->creerCreance(
            $client,
            10000,
            'Batch creance 1 (rejeter)',
            now()->addDays(30)->toDateString(),
            $admin
        );
        $this->creanceService->creerCreance(
            $client,
            15000,
            'Batch creance 2 (rejeter)',
            now()->addDays(30)->toDateString(),
            $admin
        );

        $batchKey = Str::uuid()->toString();

        // Soumettre un paiement global (créé 2 transactions en_attente avec la même batch_key)
        $result = $this->creanceService->soumettreRemboursTotal(
            $client,
            null,
            null,
            'paiement batch rejeter test',
            $batchKey
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('transactions', $result);
        $this->assertCount(2, $result['transactions']);

        $txIds = collect($result['transactions'])->map(fn ($tx) => (string) $tx['id'])->all();

        $this->actingAs($admin);
        $res = $this->postJson('/api/v1/creances/transactions/batch/' . $batchKey . '/rejeter', [
            'motif' => 'Motif test',
        ]);

        $res
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.batch_key', $batchKey)
            ->assertJsonPath('data.rejected_count', 2);

        $this->assertEquals(
            2,
            CreanceTransaction::query()->whereIn('id', $txIds)->where('statut', 'rejete')->count()
        );

        Queue::assertPushed(SendCreanceBatchRejectedMailJob::class, 1);
        Queue::assertPushed(SendCreanceBatchRejectedMailJob::class, function ($job) use ($client, $batchKey) {
            return (string) $job->clientId === (string) $client->id
                && (string) $job->batchKey === (string) $batchKey;
        });
    }

    public function test_rejeter_paiement_simple_dispatch_email_client(): void
    {
        Queue::fake();

        // Super admin role pour bypass check-permission:credits.manage
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

        $client = User::factory()->create([
            'is_pro' => true,
            'email' => 'client-rejet@example.test',
        ]);
        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 500000,
                'credit_disponible' => 500000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        $creance = $this->creanceService->creerCreance(
            $client,
            20000,
            'Creance rejet simple',
            now()->addDays(30)->toDateString(),
            $admin
        );

        $tx = $this->creanceService->soumettreRembours(
            $client,
            $creance,
            20000,
            'paiement_total'
        );

        $this->assertEquals('en_attente', $tx->statut);

        $this->actingAs($admin);
        $res = $this->postJson('/api/v1/creances/transactions/' . $tx->id . '/rejeter', [
            'motif' => 'Preuve invalide',
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseHas('creance_transactions', [
            'id' => (string) $tx->id,
            'statut' => 'rejete',
        ]);

        Queue::assertPushed(SendCreanceRejectedMailJob::class, 1);
        Queue::assertPushed(SendCreanceRejectedMailJob::class, function ($job) use ($tx) {
            return (string) $job->transactionId === (string) $tx->id;
        });
    }
    private AuditLogService $auditService;
    private CreanceService $creanceService;
    private RiskScoringService $scoringService;
    private FinancialLedgerService $ledgerService;
    private AnomalyDetectionService $anomalyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService   = new AuditLogService();
        $this->ledgerService  = new FinancialLedgerService();
        $this->anomalyService = new AnomalyDetectionService($this->auditService);
        $this->scoringService = new RiskScoringService($this->auditService);
        $this->creanceService = new CreanceService(
            $this->ledgerService,
            $this->scoringService,
            $this->anomalyService,
            $this->auditService
        );

        // Créer un admin
        $this->admin = User::factory()->create(['is_pro' => false]);

        // Créer un client PRO avec profil crédit
        $this->clientPro = User::factory()->create(['is_pro' => true]);
        // Un UserObserver peut auto-créer le profil ; on upsert pour éviter l'erreur UNIQUE.
        CreditProfile::updateOrCreate(
            ['user_id' => $this->clientPro->id],
            [
                'credit_limite'     => 500000,
                'credit_disponible' => 500000,
                'score_fiabilite'   => 60,
                'niveau_risque'     => 'moyen',
                'est_bloque'        => false,
                'total_encours'     => 0,
            ]
        );
    }

    public function test_creation_utilisateur_pro_cree_automatiquement_un_profil_credit(): void
    {
        $pro = User::factory()->create(['is_pro' => true]);

        $this->assertDatabaseHas('credit_profiles', [
            'user_id' => $pro->id,
        ]);
    }

    public function test_creation_utilisateur_non_pro_ne_cree_pas_de_profil_credit(): void
    {
        $user = User::factory()->create(['is_pro' => false]);

        $this->assertDatabaseMissing('credit_profiles', [
            'user_id' => $user->id,
        ]);
    }

    public function test_stats_sous_admin_retourne_nombre_pro_assignes_meme_sans_wallet(): void
    {
        $financeRole = Role::query()->updateOrCreate(
            ['slug' => 'finance_admin'],
            [
                'name' => 'Sous-Admin Finance',
                'description' => 'Rôle finance (tests)',
                'is_super_admin' => false,
            ]
        );

        $proRole = Role::query()->updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'PRO',
                'description' => 'Rôle pro (tests)',
                'is_super_admin' => false,
            ]
        );

        $subAdmin = User::factory()->create([
            'role_id' => $financeRole->id,
            'is_pro' => false,
        ]);

        $assignedPro = User::factory()->create([
            'role_id' => $proRole->id,
            'is_pro' => false,
            'assigned_user' => $subAdmin->id,
        ]);

        $this->actingAs($subAdmin);
        $response = $this->getJson('/api/v1/wallets/' . $subAdmin->id . '/stats');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nbClientsAssignes', 1);
    }

    // ─── Test 1 : Scoring de base ─────────────────────────────────────────

    public function test_calcul_niveau_risque(): void
    {
        $this->assertEquals('faible', $this->scoringService->determinerNiveauRisque(80));
        $this->assertEquals('moyen',  $this->scoringService->determinerNiveauRisque(65));
        $this->assertEquals('moyen',  $this->scoringService->determinerNiveauRisque(50));
        $this->assertEquals('eleve',  $this->scoringService->determinerNiveauRisque(49));
        $this->assertEquals('eleve',  $this->scoringService->determinerNiveauRisque(0));
    }

    // ─── Test 2 : Création d'une créance ──────────────────────────────────

    public function test_creation_creance_reduit_credit_disponible(): void
    {
        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            100000,
            'Commande test',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $this->assertNotNull($creance->id);
        $this->assertEquals(100000, $creance->montant_total);
        $this->assertEquals('en_attente', $creance->statut);

        // Le profil doit refléter l'encours
        $this->clientPro->refresh();
        $profil = $this->clientPro->creditProfile;
        $this->assertEquals(100000, $profil->total_encours);
        $this->assertEquals(400000, $profil->credit_disponible);
    }

    public function test_creation_creance_refuse_si_limite_depassee(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->creanceService->creerCreance(
            $this->clientPro,
            600000, // dépasse la limite de 500000
            'Trop grande commande',
            null,
            $this->admin
        );
    }

    public function test_creation_creance_refuse_si_compte_bloque(): void
    {
        $this->clientPro->creditProfile->update(['est_bloque' => true, 'motif_blocage' => 'Test']);

        $this->expectException(\RuntimeException::class);

        $this->creanceService->creerCreance(
            $this->clientPro,
            10000,
            'Test bloqué',
            null,
            $this->admin
        );
    }

    // ─── Test 3 : Ledger immuable ─────────────────────────────────────────

    public function test_ledger_entry_immutable(): void
    {
        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            50000,
            'Test ledger',
            null,
            $this->admin
        );

        $entry = LedgerEntry::where('user_id', $this->clientPro->id)->first();
        $this->assertNotNull($entry);

        // Tenter une modification doit lever une exception
        $this->expectException(\RuntimeException::class);
        $entry->update(['montant' => 999]);
    }

    public function test_ledger_hash_integrite(): void
    {
        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            25000,
            'Test hash',
            null,
            $this->admin
        );

        $entry = LedgerEntry::where('user_id', $this->clientPro->id)->first();
        $this->assertNotNull($entry->hash_integrite);
        $this->assertEquals(64, strlen($entry->hash_integrite)); // SHA256 = 64 hex chars
    }

    // ─── Test 4 : Validation paiement ─────────────────────────────────────

    public function test_validation_paiement_met_a_jour_creance(): void
    {
        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            100000,
            'Test validation',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        // Soumettre paiement
        $this->actingAs($this->clientPro);
        $tx = $this->creanceService->soumettreRembours(
            $this->clientPro,
            $creance,
            100000,
            'paiement_total'
        );

        $this->assertEquals('en_attente', $tx->statut);

        // Valider
        $creanceUpdated = $this->creanceService->validerPaiement($tx, $this->admin);

        $this->assertEquals('payee', $creanceUpdated->statut);
        $this->assertEquals(0, $creanceUpdated->montant_restant);

        // Vérifier ledger : 2 entrées (débit + crédit)
        $nb = LedgerEntry::where('user_id', $this->clientPro->id)->count();
        $this->assertEquals(2, $nb);
    }

    public function test_validation_paiement_partiel(): void
    {
        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            100000,
            'Test partiel',
            null,
            $this->admin
        );

        $this->actingAs($this->clientPro);
        $tx = $this->creanceService->soumettreRembours(
            $this->clientPro,
            $creance,
            40000,
            'paiement_partiel'
        );

        $creanceUpdated = $this->creanceService->validerPaiement($tx, $this->admin);

        $this->assertEquals('partiellement_payee', $creanceUpdated->statut);
        $this->assertEquals(60000, $creanceUpdated->montant_restant);
    }

    public function test_double_validation_impossible(): void
    {
        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            50000,
            'Test double validation',
            null,
            $this->admin
        );

        $this->actingAs($this->clientPro);
        $tx = $this->creanceService->soumettreRembours(
            $this->clientPro,
            $creance,
            50000,
            'paiement_total'
        );

        // Première validation
        $this->creanceService->validerPaiement($tx, $this->admin);

        // Deuxième validation doit échouer
        $this->expectException(\RuntimeException::class);
        $this->creanceService->validerPaiement($tx, $this->admin);
    }

    // ─── Test 5 : Recalcul score ──────────────────────────────────────────

    public function test_recalcul_score_apres_paiement(): void
    {
        $profil = $this->clientPro->creditProfile;
        $scoreInitial = $profil->score_fiabilite;

        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            10000,
            'Test score',
            now()->addDays(10)->toDateString(),
            $this->admin
        );

        $this->actingAs($this->clientPro);
        $tx = $this->creanceService->soumettreRembours(
            $this->clientPro,
            $creance,
            10000,
            'paiement_total'
        );

        $this->creanceService->validerPaiement($tx, $this->admin);

        $this->clientPro->refresh();
        // Score doit être recalculé (enregistrement dans credit_score_histories)
        $this->assertDatabaseHas('credit_score_histories', [
            'user_id'     => $this->clientPro->id,
            'declencheur' => 'paiement_valide',
        ]);
    }

    public function test_recalcul_score_ne_modifie_pas_limite_admin(): void
    {
        $this->clientPro->creditProfile->update([
            'credit_limite' => 30000000,
            'credit_disponible' => 30000000,
            'score_fiabilite' => 80,
            'niveau_risque' => 'faible',
            'total_encours' => 0,
        ]);

        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            5000000,
            'Créance pour forcer le risque',
            now()->subDays(5)->toDateString(),
            $this->admin
        );

        $creance->update([
            'statut' => 'en_retard',
            'jours_retard' => 15,
        ]);

        $updated = $this->scoringService->recalculerScore($this->clientPro, 'recalcul_manuel');

        $this->assertEquals(30000000.0, (float) $updated->credit_limite);
        $this->assertEquals('eleve', $updated->niveau_risque);
        $this->assertEquals(
            max(0, (float) $updated->credit_limite - (float) $updated->total_encours),
            (float) $updated->credit_disponible
        );
    }

    // ─── Test 6 : API Endpoints ───────────────────────────────────────────

    public function test_endpoint_dashboard_risk_requiert_admin(): void
    {
        $this->actingAs($this->clientPro);
        $response = $this->getJson('/api/v1/risk/dashboard');
        // Doit être 403 car client non admin
        $this->assertContains($response->status(), [401, 403]);
    }

    public function test_admin_peut_resoudre_anomalies_critiques_client_en_masse(): void
    {
        // Super admin role pour bypass check-permission:credits.manage (middleware).
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

        $client = User::factory()->create(['is_pro' => true]);
        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 500000,
                'credit_disponible' => 500000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => true,
                'total_encours' => 0,
                'motif_blocage' => 'Blocage automatique : 3 anomalies critiques non résolues.',
            ]
        );

        // 3 anomalies critiques non résolues
        for ($i = 0; $i < 3; $i++) {
            AnomalyFlag::create([
                'user_id' => $client->id,
                'type_anomalie' => 'montant_anormalement_eleve',
                'niveau' => 'critique',
                'description' => 'Test anomalie critique',
                'resolved' => false,
            ]);
        }

        $this->assertEquals(3, AnomalyFlag::query()
            ->where('user_id', $client->id)
            ->where('niveau', 'critique')
            ->where('resolved', false)
            ->count());

        $this->actingAs($admin);
        $response = $this->postJson(
            '/api/v1/risk/clients/' . $client->id . '/anomalies/resoudre-critiques',
            ['note' => 'Résolution admin (test)']
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.resolved', 3)
            ->assertJsonPath('data.auto_unblocked', true);

        $this->assertDatabaseHas('credit_profiles', [
            'user_id' => $client->id,
            'est_bloque' => false,
            'motif_blocage' => null,
        ]);

        $this->assertEquals(0, AnomalyFlag::query()
            ->where('user_id', $client->id)
            ->where('niveau', 'critique')
            ->where('resolved', false)
            ->count());

        $this->assertDatabaseHas('anomaly_flags', [
            'user_id' => $client->id,
            'niveau' => 'critique',
            'resolved' => true,
            'note_resolution' => 'Résolution admin (test)',
        ]);
    }

    public function test_admin_peut_lister_les_comptes_credit_avec_impaye_et_limite(): void
    {
        // Super admin role pour bypass check-permission:credits.manage (middleware).
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

        $client = User::factory()->create(['is_pro' => true]);
        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 30_000_000,
                'credit_disponible' => 30_000_000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        // Crée une créance impayée (le service met à jour total_encours / credit_disponible)
        $this->creanceService->creerCreance(
            $client,
            15_000_000,
            'Créance impayée (test)',
            now()->addDays(30)->toDateString(),
            $admin
        );

        $res = $this->actingAs($admin)->getJson('/api/v1/risk/clients?per_page=50');
        $res->assertOk();
        $res->assertJsonPath('success', true);

        $items = $res->json('data.data');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        $found = collect($items)->first(fn($it) => (string) data_get($it, 'user.id') === (string) $client->id);
        $this->assertNotNull($found);

        $this->assertArrayHasKey('credit_limite', $found);
        $this->assertArrayHasKey('total_encours', $found);
        $this->assertGreaterThan(0, (float) $found['credit_limite']);
        $this->assertGreaterThan(0, (float) $found['total_encours']);
    }

    public function test_sous_admin_risk_clients_liste_tous_les_pro_assignes_meme_sans_profil_credit(): void
    {
        $perm = Permission::query()->updateOrCreate(
            ['slug' => 'credits.manage'],
            [
                'name' => 'Gérer crédit',
                'module' => 'Crédit',
                'description' => 'Permission crédit (tests)',
            ]
        );

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
            'is_pro' => false,
        ]);

        $assignedWithProfile = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => $financeAdmin->id,
        ]);
        $assignedWithoutProfile = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => $financeAdmin->id,
        ]);
        $nonAssignedPro = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => null,
        ]);

        CreditProfile::updateOrCreate(
            ['user_id' => $assignedWithProfile->id],
            [
                'credit_limite' => 900000,
                'credit_disponible' => 850000,
                'score_fiabilite' => 72,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 50000,
            ]
        );

        CreditProfile::where('user_id', $assignedWithoutProfile->id)->delete();
        CreditProfile::where('user_id', $nonAssignedPro->id)->delete();

        $res = $this->actingAs($financeAdmin)->getJson('/api/v1/risk/clients?per_page=50');
        $res->assertOk()->assertJsonPath('success', true);

        $items = collect($res->json('data.data'));
        $ids = $items->map(fn($it) => (string) data_get($it, 'user.id'))->values();

        $this->assertTrue($ids->contains((string) $assignedWithProfile->id));
        $this->assertTrue($ids->contains((string) $assignedWithoutProfile->id));
        $this->assertFalse($ids->contains((string) $nonAssignedPro->id));

        $withoutProfileRow = $items->first(
            fn($it) => (string) data_get($it, 'user.id') === (string) $assignedWithoutProfile->id
        );

        $this->assertNotNull($withoutProfileRow);
        $this->assertEquals(0.0, (float) data_get($withoutProfileRow, 'credit_limite'));
        $this->assertEquals(0.0, (float) data_get($withoutProfileRow, 'total_encours'));
    }

    public function test_sous_admin_peut_acceder_risk_clients_sans_permission_explicite_credits_manage(): void
    {
        $financeRole = Role::query()->updateOrCreate(
            ['slug' => 'finance_admin'],
            [
                'name' => 'Sous-Admin Finance',
                'description' => 'Rôle finance (tests)',
                'is_super_admin' => false,
            ]
        );

        $financeRole->permissions()->detach();

        $financeAdmin = User::factory()->create([
            'role_id' => $financeRole->id,
            'is_pro' => false,
        ]);

        $assignedPro = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => $financeAdmin->id,
        ]);

        CreditProfile::updateOrCreate(
            ['user_id' => $assignedPro->id],
            [
                'credit_limite' => 250000,
                'credit_disponible' => 200000,
                'score_fiabilite' => 62,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 50000,
            ]
        );

        $res = $this->actingAs($financeAdmin)->getJson('/api/v1/risk/clients?per_page=50');

        $res->assertOk()->assertJsonPath('success', true);

        $ids = collect($res->json('data.data'))
            ->map(fn($it) => (string) data_get($it, 'user.id'))
            ->values();

        $this->assertTrue($ids->contains((string) $assignedPro->id));
    }

    public function test_endpoint_recalculer_score_ne_modifie_pas_limite_admin(): void
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

        $client = User::factory()->create(['is_pro' => true]);
        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 30_000_000,
                'credit_disponible' => 30_000_000,
                'score_fiabilite' => 80,
                'niveau_risque' => 'faible',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        $creance = $this->creanceService->creerCreance(
            $client,
            5_000_000,
            'Créance pour recalcul score endpoint',
            now()->subDays(5)->toDateString(),
            $admin
        );

        $creance->update([
            'statut' => 'en_retard',
            'jours_retard' => 15,
        ]);

        $this->actingAs($admin);
        $response = $this->postJson('/api/v1/risk/clients/' . $client->id . '/recalculer-score');

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $client->refresh();
        $profil = $client->creditProfile;

        $this->assertNotNull($profil);
        $this->assertEquals(30_000_000.0, (float) $profil->credit_limite);
        $this->assertEquals('eleve', $profil->niveau_risque);
        $this->assertEquals(
            max(0, (float) $profil->credit_limite - (float) $profil->total_encours),
            (float) $profil->credit_disponible
        );
    }

    public function test_endpoint_mes_creances_retourne_profil(): void
    {
        $this->clientPro->creditProfile()->update(['total_encours' => 5000]);

        $this->actingAs($this->clientPro);
        $response = $this->getJson('/api/v1/mes-creances');

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data', 'credit_profile']);
    }

    public function test_endpoint_mes_creances_resume_retourne_totaux_par_statut(): void
    {
        // Créer 2 créances pour le client, avec statuts différents.
        $c1 = $this->creanceService->creerCreance(
            $this->clientPro,
            10_000,
            'Creance resume 1',
            now()->addDays(10)->toDateString(),
            $this->admin
        );
        $c2 = $this->creanceService->creerCreance(
            $this->clientPro,
            20_000,
            'Creance resume 2',
            now()->addDays(20)->toDateString(),
            $this->admin
        );

        // Forcer un statut "en_retard" pour en avoir au moins 2.
        $c2->update(['statut' => 'en_retard']);

        $this->actingAs($this->clientPro);
        $res = $this->getJson('/api/v1/mes-creances/resume');

        $res->assertOk();
        $res->assertJsonPath('success', true);

        $rows = $res->json('data');
        $this->assertIsArray($rows);

        // Doit contenir au moins une ligne pour "en_attente" (statut par défaut à la création)
        // et une pour "en_retard" (qu'on vient de forcer).
        $statuts = collect($rows)->pluck('statut')->all();
        $this->assertContains('en_attente', $statuts);
        $this->assertContains('en_retard', $statuts);

        // Vérifie la structure attendue des totaux.
        $first = $rows[0] ?? null;
        $this->assertIsArray($first);
        $this->assertArrayHasKey('statut', $first);
        $this->assertArrayHasKey('nb', $first);
        $this->assertArrayHasKey('total_restant', $first);
    }

    public function test_compte_bloque_peut_consulter_creances_mais_pas_payer(): void
    {
        $this->clientPro->creditProfile->update([
            'est_bloque' => true,
            'motif_blocage' => 'Blocage automatique : 3 anomalies critiques non résolues.',
        ]);

        $this->actingAs($this->clientPro);

        // Lecture seule autorisée
        $response = $this->getJson('/api/v1/mes-creances');
        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data', 'credit_profile'])
            ->assertJsonPath('credit_profile.est_bloque', true);

        // Action sensible toujours interdite
        $payerTotal = $this->postJson('/api/v1/mes-creances/payer-total', []);
        $payerTotal
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_endpoint_payer_total_dispatch_un_seul_job_email_batch(): void
    {
        Mail::fake();

        // Forcer une liste de destinataires admin pour garantir le dispatch.
        config(['edgpay.credit.reimbursement_notify_emails' => ['admin@example.test']]);
        // En tests on veut un envoi immédiat pour pouvoir asserter sur Mail.
        config(['edgpay.credit.reimbursement_mail_mode' => 'sync']);

        // Créer 2 créances impayées pour que la soumission globale génère plusieurs transactions.
        $this->creanceService->creerCreance(
            $this->clientPro,
            10000,
            'Creance 1 (payer-total test)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );
        $this->creanceService->creerCreance(
            $this->clientPro,
            15000,
            'Creance 2 (payer-total test)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $batchKey = Str::uuid()->toString();

        $this->actingAs($this->clientPro);
        $response = $this
            ->withHeader('X-Idempotency-Key', $batchKey)
            ->postJson('/api/v1/mes-creances/payer-total', []);

        $response
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.batch_key', $batchKey);

        Mail::assertSent(CreanceReimbursementBatchSubmittedMail::class, 1);
        Mail::assertSent(CreanceReimbursementBatchSubmittedMail::class, function ($mailable) use ($batchKey) {
            return $mailable->hasTo('admin@example.test')
                && (string) $mailable->batchKey === (string) $batchKey
                && (string) $mailable->client->id === (string) $this->clientPro->id;
        });

        // S'assurer qu'on ne fait plus un email par transaction pour payer-total.
        Mail::assertNotSent(CreanceReimbursementSubmittedMail::class);
    }

    public function test_endpoint_payer_creance_envoie_un_email_soumission(): void
    {
        Mail::fake();
        config(['edgpay.credit.reimbursement_notify_emails' => ['admin@example.test']]);
        config(['edgpay.credit.reimbursement_mail_mode' => 'sync']);

        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            20000,
            'Creance (payer simple test)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $this->actingAs($this->clientPro);
        $response = $this
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson(
                '/api/v1/creances/' . $creance->id . '/payer',
                [
                    'montant' => 5000,
                    'type' => 'paiement_partiel',
                    'notes' => 'test mail soumission',
                ]
            );

        $response
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        Mail::assertSent(CreanceReimbursementSubmittedMail::class, 1);
        Mail::assertSent(CreanceReimbursementSubmittedMail::class, function ($mailable) use ($creance) {
            return $mailable->hasTo('admin@example.test')
                && (string) $mailable->client->id === (string) $this->clientPro->id
                && (string) $mailable->creance->id === (string) $creance->id;
        });
    }

    public function test_surpaiement_payer_creance_credite_avoir_wallet(): void
    {
        Mail::fake();
        Queue::fake();

        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            10000,
            'Creance (surpaiement test)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $this->assertDatabaseMissing('wallets', [
            'user_id' => (string) $this->clientPro->id,
        ]);

        $this->actingAs($this->clientPro);
        $res = $this
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson('/api/v1/creances/' . $creance->id . '/payer', [
                'montant' => 15000,
                'type' => 'paiement_partiel',
                'notes' => 'surpaiement -> avoir',
            ]);

        $res
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('avoir_montant', 5000);

        $txId = (string) $res->json('data.id');
        $this->assertNotEmpty($txId);

        $this->assertDatabaseHas('creance_transactions', [
            'id' => $txId,
            'type' => 'paiement_total',
            'montant' => '10000.00',
            'user_id' => (string) $this->clientPro->id,
            'creance_id' => (string) $creance->id,
        ]);

        $wallet = Wallet::query()->where('user_id', $this->clientPro->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(5000, (int) $wallet->cash_available);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => (string) $wallet->id,
            'user_id' => (string) $this->clientPro->id,
            'type' => 'credit_note',
            'amount' => 5000,
            'reference' => 'credit_note_overpay_creance_tx:' . $txId,
        ]);

        $this->clientPro->refresh();
        $this->assertEquals(5000, (int) $this->clientPro->solde_portefeuille);
    }

    public function test_surpaiement_payer_total_credite_avoir_wallet(): void
    {
        Mail::fake();
        Queue::fake();

        $this->creanceService->creerCreance(
            $this->clientPro,
            10000,
            'Creance 1 (surpaiement payer-total)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );
        $this->creanceService->creerCreance(
            $this->clientPro,
            15000,
            'Creance 2 (surpaiement payer-total)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $batchKey = Str::uuid()->toString();

        $this->actingAs($this->clientPro);
        $res = $this
            ->withHeader('X-Idempotency-Key', $batchKey)
            ->postJson('/api/v1/mes-creances/payer-total', [
                'montant' => 30000,
                'notes' => 'surpaiement payer-total -> avoir',
            ]);

        $res
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.batch_key', $batchKey)
            ->assertJsonPath('data.avoir_montant', 5000);

        $wallet = Wallet::query()->where('user_id', $this->clientPro->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(5000, (int) $wallet->cash_available);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => (string) $wallet->id,
            'user_id' => (string) $this->clientPro->id,
            'type' => 'credit_note',
            'amount' => 5000,
            'reference' => 'credit_note_overpay_creance_batch:' . $batchKey,
        ]);

        $this->clientPro->refresh();
        $this->assertEquals(5000, (int) $this->clientPro->solde_portefeuille);
    }

    public function test_admin_peut_valider_transaction_surpaiement_en_creditant_avoir(): void
    {
        Mail::fake();
        Queue::fake();

        // Super admin pour bypass les checks d'assignation.
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

        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            10000,
            'Creance (admin validate surpaiement test)',
            now()->addDays(30)->toDateString(),
            $superAdmin
        );

        $this->assertDatabaseMissing('wallets', [
            'user_id' => (string) $this->clientPro->id,
        ]);

        // Simuler une ancienne transaction soumise avec un montant > restant.
        $tx = CreanceTransaction::create([
            'creance_id' => $creance->id,
            'user_id' => $this->clientPro->id,
            'montant' => 15000,
            'montant_avant' => 10000,
            'montant_apres' => -5000,
            'type' => 'paiement_partiel',
            'statut' => 'en_attente',
            'notes' => 'soumission ancienne: surpaiement',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $this->actingAs($superAdmin);
        $res = $this->postJson('/api/v1/creances/transactions/' . $tx->id . '/valider');

        $res
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('creance_transactions', [
            'id' => (string) $tx->id,
            'statut' => 'valide',
            'montant' => '15000.00',
            'montant_avant' => '10000.00',
            'montant_apres' => '0.00',
        ]);

        $creance->refresh();
        $this->assertEquals('payee', (string) $creance->statut);
        $this->assertEquals(0.0, (float) $creance->montant_restant);

        $wallet = Wallet::query()->where('user_id', $this->clientPro->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(5000, (int) $wallet->cash_available);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => (string) $wallet->id,
            'user_id' => (string) $this->clientPro->id,
            'type' => 'credit_note',
            'amount' => 5000,
            'reference' => 'credit_note_overpay_creance_tx:' . (string) $tx->id,
        ]);

        $this->clientPro->refresh();
        $this->assertEquals(5000, (int) $this->clientPro->solde_portefeuille);
    }

    public function test_email_reçu_par_admin_ayant_permission_credits_manage_si_aucun_recipient_configure(): void
    {
        Mail::fake();
        config(['edgpay.credit.reimbursement_notify_emails' => []]);
        config(['edgpay.credit.reimbursement_mail_mode' => 'sync']);

        $perm = Permission::query()->updateOrCreate(
            ['slug' => 'credits.manage'],
            [
                'name' => 'Gérer crédit',
                'module' => 'Crédit',
                'description' => 'Permission crédit (tests)',
            ]
        );

        $role = Role::query()->create([
            'name' => 'Admin Crédit (tests)',
            'slug' => 'admin_credit_test',
            'description' => 'Rôle test',
            'is_super_admin' => false,
        ]);
        $role->permissions()->attach($perm->id, ['access_level' => 'oui']);

        $creditAdmin = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'credit.admin@example.test',
            'is_pro' => false,
        ]);

        $creance = $this->creanceService->creerCreance(
            $this->clientPro,
            12000,
            'Creance (notify credits.manage test)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $this->actingAs($this->clientPro);
        $response = $this
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson(
                '/api/v1/creances/' . $creance->id . '/payer',
                [
                    'montant' => 2000,
                    'type' => 'paiement_partiel',
                ]
            );

        $response->assertStatus(201);

        Mail::assertSent(CreanceReimbursementSubmittedMail::class, function ($mailable) use ($creditAdmin) {
            return $mailable->hasTo($creditAdmin->email);
        });
    }

    public function test_sous_admin_ne_recoit_pas_email_remboursement_si_pro_non_assigne(): void
    {
        Mail::fake();
        config(['edgpay.credit.reimbursement_notify_emails' => []]);
        config(['edgpay.credit.reimbursement_mail_mode' => 'sync']);

        $perm = Permission::query()->updateOrCreate(
            ['slug' => 'credits.manage'],
            [
                'name' => 'Gérer crédit',
                'module' => 'Crédit',
                'description' => 'Permission crédit (tests)',
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

        $client = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => null,
        ]);

        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 500000,
                'credit_disponible' => 500000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        $creance = $this->creanceService->creerCreance(
            $client,
            12000,
            'Creance (sub-admin recipient guard test)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $this->actingAs($client);
        $response = $this
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson(
                '/api/v1/creances/' . $creance->id . '/payer',
                [
                    'montant' => 2000,
                    'type' => 'paiement_partiel',
                ]
            );

        $response->assertStatus(201);

        Mail::assertSent(CreanceReimbursementSubmittedMail::class, function ($mailable) use ($superAdmin, $financeAdmin) {
            return $mailable->hasTo($superAdmin->email) && ! $mailable->hasTo($financeAdmin->email);
        });
    }

    public function test_pro_assigne_notifie_uniquement_sous_admin_pour_remboursement_meme_si_env_configure(): void
    {
        Mail::fake();
        config(['edgpay.credit.reimbursement_notify_emails' => ['superadmin@example.test']]);
        config(['edgpay.credit.reimbursement_mail_mode' => 'sync']);

        $perm = Permission::query()->updateOrCreate(
            ['slug' => 'credits.manage'],
            [
                'name' => 'Gérer crédit',
                'module' => 'Crédit',
                'description' => 'Permission crédit (tests)',
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

        $client = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => $financeAdmin->id,
        ]);

        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 500000,
                'credit_disponible' => 500000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        $creance = $this->creanceService->creerCreance(
            $client,
            12000,
            'Creance (assigned recipient guard test)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $this->actingAs($client);
        $response = $this
            ->withHeader('X-Idempotency-Key', Str::uuid()->toString())
            ->postJson(
                '/api/v1/creances/' . $creance->id . '/payer',
                [
                    'montant' => 2000,
                    'type' => 'paiement_partiel',
                ]
            );

        $response->assertStatus(201);

        Mail::assertSent(CreanceReimbursementSubmittedMail::class, function ($mailable) use ($financeAdmin, $superAdmin) {
            return $mailable->hasTo($financeAdmin->email)
                && ! $mailable->hasTo($superAdmin->email);
        });
    }

    public function test_sous_admin_ne_voit_que_transactions_en_attente_de_ses_pro_assignes(): void
    {
        $perm = Permission::query()->updateOrCreate(
            ['slug' => 'credits.manage'],
            [
                'name' => 'Gérer crédit',
                'module' => 'Crédit',
                'description' => 'Permission crédit (tests)',
            ]
        );

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
            'is_pro' => false,
        ]);

        $assignedClient = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => $financeAdmin->id,
        ]);
        $nonAssignedClient = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => null,
        ]);

        foreach ([$assignedClient, $nonAssignedClient] as $client) {
            CreditProfile::updateOrCreate(
                ['user_id' => $client->id],
                [
                    'credit_limite' => 500000,
                    'credit_disponible' => 500000,
                    'score_fiabilite' => 60,
                    'niveau_risque' => 'moyen',
                    'est_bloque' => false,
                    'total_encours' => 0,
                ]
            );
        }

        $creanceAssigned = $this->creanceService->creerCreance(
            $assignedClient,
            10000,
            'Créance assignée (pending list)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $creanceNonAssigned = $this->creanceService->creerCreance(
            $nonAssignedClient,
            12000,
            'Créance non assignée (pending list)',
            now()->addDays(30)->toDateString(),
            $this->admin
        );

        $txAssigned = $this->creanceService->soumettreRembours(
            $assignedClient,
            $creanceAssigned,
            4000,
            'paiement_partiel'
        );

        $txNonAssigned = $this->creanceService->soumettreRembours(
            $nonAssignedClient,
            $creanceNonAssigned,
            3000,
            'paiement_partiel'
        );

        $this->assertEquals('en_attente', $txAssigned->statut);
        $this->assertEquals('en_attente', $txNonAssigned->statut);

        $res = $this->actingAs($financeAdmin)
            ->getJson('/api/v1/creances/transactions/en-attente?per_page=50');

        $res->assertOk()->assertJsonPath('success', true);

        $ids = collect($res->json('data.data'))->pluck('id')->map(fn($v) => (string) $v)->values();
        $this->assertTrue($ids->contains((string) $txAssigned->id));
        $this->assertFalse($ids->contains((string) $txNonAssigned->id));
    }

    public function test_sous_admin_ne_voit_que_transactions_validees_de_ses_pro_assignes(): void
    {
        $perm = Permission::query()->updateOrCreate(
            ['slug' => 'credits.manage'],
            [
                'name' => 'Gérer crédit',
                'module' => 'Crédit',
                'description' => 'Permission crédit (tests)',
            ]
        );

        $financeRole = Role::query()->updateOrCreate(
            ['slug' => 'finance_admin'],
            [
                'name' => 'Sous-Admin Finance',
                'description' => 'Rôle finance (tests)',
                'is_super_admin' => false,
            ]
        );
        $financeRole->permissions()->syncWithoutDetaching([$perm->id => ['access_level' => 'oui']]);

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

        $financeAdmin = User::factory()->create([
            'role_id' => $financeRole->id,
            'is_pro' => false,
        ]);

        $assignedClient = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => $financeAdmin->id,
        ]);
        $nonAssignedClient = User::factory()->create([
            'is_pro' => true,
            'assigned_user' => null,
        ]);

        foreach ([$assignedClient, $nonAssignedClient] as $client) {
            CreditProfile::updateOrCreate(
                ['user_id' => $client->id],
                [
                    'credit_limite' => 500000,
                    'credit_disponible' => 500000,
                    'score_fiabilite' => 60,
                    'niveau_risque' => 'moyen',
                    'est_bloque' => false,
                    'total_encours' => 0,
                ]
            );
        }

        $creanceAssigned = $this->creanceService->creerCreance(
            $assignedClient,
            10000,
            'Créance assignée (validated list)',
            now()->addDays(30)->toDateString(),
            $superAdmin
        );

        $creanceNonAssigned = $this->creanceService->creerCreance(
            $nonAssignedClient,
            12000,
            'Créance non assignée (validated list)',
            now()->addDays(30)->toDateString(),
            $superAdmin
        );

        $txAssigned = $this->creanceService->soumettreRembours(
            $assignedClient,
            $creanceAssigned,
            4000,
            'paiement_partiel'
        );

        $txNonAssigned = $this->creanceService->soumettreRembours(
            $nonAssignedClient,
            $creanceNonAssigned,
            3000,
            'paiement_partiel'
        );

        $this->creanceService->validerPaiement($txAssigned, $superAdmin);
        $this->creanceService->validerPaiement($txNonAssigned, $superAdmin);

        $res = $this->actingAs($financeAdmin)
            ->getJson('/api/v1/creances/transactions/validees?per_page=50');

        $res->assertOk()->assertJsonPath('success', true);

        $ids = collect($res->json('data.data'))->pluck('id')->map(fn($v) => (string) $v)->values();
        $this->assertTrue($ids->contains((string) $txAssigned->id));
        $this->assertFalse($ids->contains((string) $txNonAssigned->id));
    }

    public function test_admin_ne_peut_pas_creer_creance_si_depasse_limite_meme_si_bypass_envoye(): void
    {
        // Super admin role pour bypass check-permission:credits.manage (middleware).
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

        $client = User::factory()->create(['is_pro' => true]);
        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 500000,
                'credit_disponible' => 500000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        $this->actingAs($admin);
        $response = $this->postJson('/api/v1/creances', [
            'client_id' => (string) $client->id,
            'montant' => 600000, // dépasse la limite/disponible
            'description' => 'Impayé au-delà de la limite',
            'date_echeance' => now()->addDays(30)->toDateString(),
            'metadata' => [
                'taux_interet' => 5,
                'bypass_credit_limit' => true,
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_endpoint_valider_paiement_batch_valide_toutes_les_transactions(): void
    {
        // Super admin role pour bypass check-permission:credits.manage
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

        $client = User::factory()->create(['is_pro' => true]);
        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 500000,
                'credit_disponible' => 500000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        // Créer 2 créances impayées
        $this->creanceService->creerCreance(
            $client,
            10000,
            'Batch creance 1',
            now()->addDays(30)->toDateString(),
            $admin
        );
        $this->creanceService->creerCreance(
            $client,
            15000,
            'Batch creance 2',
            now()->addDays(30)->toDateString(),
            $admin
        );

        $batchKey = Str::uuid()->toString();

        // Soumettre un paiement global (créé 2 transactions en_attente avec la même idempotency_key)
        $result = $this->creanceService->soumettreRemboursTotal(
            $client,
            null,
            null,
            'paiement batch test',
            $batchKey
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('transactions', $result);
        $this->assertCount(2, $result['transactions']);

        $txIds = collect($result['transactions'])->map(fn ($tx) => $tx['id'])->all();

        $this->actingAs($admin);
        $response = $this->postJson(
            '/api/v1/creances/transactions/batch/' . $batchKey . '/valider'
        );

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.batch_key', $batchKey)
            ->assertJsonPath('data.validated_count', 2);

        $this->assertEquals(
            2,
            CreanceTransaction::query()->whereIn('id', $txIds)->where('statut', 'valide')->count()
        );
    }

    public function test_endpoint_valider_paiement_batch_dispatch_recu_client(): void
    {
        Queue::fake();

        // Super admin role pour bypass check-permission:credits.manage
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

        $client = User::factory()->create([
            'is_pro' => true,
            'email' => 'client@example.test',
        ]);

        CreditProfile::updateOrCreate(
            ['user_id' => $client->id],
            [
                'credit_limite' => 500000,
                'credit_disponible' => 500000,
                'score_fiabilite' => 60,
                'niveau_risque' => 'moyen',
                'est_bloque' => false,
                'total_encours' => 0,
            ]
        );

        // Créer 2 créances impayées
        $this->creanceService->creerCreance(
            $client,
            10000,
            'Batch creance 1 (recu test)',
            now()->addDays(30)->toDateString(),
            $admin
        );
        $this->creanceService->creerCreance(
            $client,
            15000,
            'Batch creance 2 (recu test)',
            now()->addDays(30)->toDateString(),
            $admin
        );

        $batchKey = Str::uuid()->toString();

        // Soumettre un paiement global (créé 2 transactions en_attente avec la même batch_key)
        $result = $this->creanceService->soumettreRemboursTotal(
            $client,
            null,
            null,
            'paiement batch recu test',
            $batchKey
        );

        $this->assertCount(2, $result['transactions']);
        $txIds = collect($result['transactions'])->map(fn ($tx) => (string) $tx['id'])->all();

        $this->actingAs($admin);
        $response = $this->postJson('/api/v1/creances/transactions/batch/' . $batchKey . '/valider');

        $response->assertStatus(200)->assertJsonPath('success', true);

        // Un seul reçu récapitulatif par batch
        Queue::assertPushed(SendCreanceBatchValidatedReceiptMailJob::class, 1);
        Queue::assertPushed(SendCreanceBatchValidatedReceiptMailJob::class, function ($job) use ($client, $batchKey) {
            return (string) $job->clientId === (string) $client->id
                && (string) $job->batchKey === (string) $batchKey;
        });

        // Plus de reçu par transaction en batch
        Queue::assertNotPushed(SendCreanceValidatedReceiptMailJob::class);
    }
}
