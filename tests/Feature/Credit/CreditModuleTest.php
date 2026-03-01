<?php

namespace Tests\Feature\Credit;

use App\Models\CreditProfile;
use App\Models\Creance;
use App\Models\CreanceTransaction;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\AnomalyDetectionService;
use App\Services\AuditLogService;
use App\Services\CreanceService;
use App\Services\FinancialLedgerService;
use App\Services\RiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        CreditProfile::create([
            'user_id'           => $this->clientPro->id,
            'credit_limite'     => 500000,
            'credit_disponible' => 500000,
            'score_fiabilite'   => 60,
            'niveau_risque'     => 'moyen',
            'est_bloque'        => false,
            'total_encours'     => 0,
        ]);
    }

    // ─── Test 1 : Scoring de base ─────────────────────────────────────────

    /** @test */
    public function test_calcul_niveau_risque(): void
    {
        $this->assertEquals('faible', $this->scoringService->determinerNiveauRisque(80));
        $this->assertEquals('moyen',  $this->scoringService->determinerNiveauRisque(65));
        $this->assertEquals('moyen',  $this->scoringService->determinerNiveauRisque(50));
        $this->assertEquals('eleve',  $this->scoringService->determinerNiveauRisque(49));
        $this->assertEquals('eleve',  $this->scoringService->determinerNiveauRisque(0));
    }

    // ─── Test 2 : Création d'une créance ──────────────────────────────────

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    /** @test */
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

    // ─── Test 6 : API Endpoints ───────────────────────────────────────────

    /** @test */
    public function test_endpoint_dashboard_risk_requiert_admin(): void
    {
        $this->actingAs($this->clientPro);
        $response = $this->getJson('/api/v1/risk/dashboard');
        // Doit être 403 car client non admin
        $this->assertContains($response->status(), [401, 403]);
    }

    /** @test */
    public function test_endpoint_mes_creances_retourne_profil(): void
    {
        $this->clientPro->creditProfile()->update(['total_encours' => 5000]);

        $this->actingAs($this->clientPro);
        $response = $this->getJson('/api/v1/mes-creances');

        $response->assertStatus(200)
                 ->assertJsonStructure(['success', 'data', 'credit_profile']);
    }
}
