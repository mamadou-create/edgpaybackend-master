<?php

namespace Tests\Feature\Troc;

use App\Models\Role;
use App\Models\TrocPhonePrice;
use App\Models\User;
use App\Services\TrocConditionAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TrocFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_can_upload_a_troc_image_and_receive_analysis(): void
    {
        Storage::fake('public');

        $this->mock(TrocConditionAssessmentService::class, function ($mock): void {
            $mock->shouldReceive('analyzeStoredImage')
                ->once()
                ->andReturn([
                    'provider' => 'chatgpt',
                    'model' => 'gpt-4.1-mini',
                    'overall_condition' => 'good',
                    'confidence' => 0.86,
                    'detected_issues' => [
                        'screen_scratches' => true,
                        'screen_cracks' => false,
                        'back_glass_damage' => false,
                        'frame_dents' => true,
                        'camera_damage' => false,
                    ],
                    'notes' => ['Micro-rayures visibles sur l ecran.'],
                    'recommended_questions' => ['Face ID fonctionne-t-il ?'],
                    'image_url' => 'http://localhost/storage/troc/test.png',
                    'source' => 'vision',
                ]);
        });

        $this->actingAs($this->createClientUser(), 'api');

        $response = $this->postJson('/api/v1/troc/upload', [
            'image' => UploadedFile::fake()->image('iphone.png', 400, 800),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.analysis.source', 'vision')
            ->assertJsonPath('data.analysis.detected_issues.screen_scratches', true)
            ->assertJsonPath('data.analysis.detected_issues.frame_dents', true)
            ->assertJsonPath('data.analysis.confidence', 0.86);

        $storedPath = (string) $response->json('data.path');

        $this->assertNotEmpty($storedPath);
        $this->assertTrue(Storage::disk('public')->exists($storedPath));
    }

    #[Test]
    public function evaluate_endpoint_applies_detailed_and_image_based_deductions(): void
    {
        TrocPhonePrice::query()->create([
            'model' => 'iPhone 12',
            'storage' => '128GB',
            'base_price' => 270,
        ]);

        $this->actingAs($this->createClientUser(), 'api');

        $response = $this->postJson('/api/v1/troc/evaluate', [
            'model' => 'iPhone 12',
            'storage' => '128GB',
            'battery' => 84,
            'condition' => 'scratched',
            'condition_details' => [
                'screen_condition' => 'good',
                'back_condition' => 'good',
                'frame_condition' => 'good',
                'camera_condition' => 'unknown',
                'face_id_works' => false,
                'repaired' => true,
                'charger_included' => true,
                'box_included' => false,
            ],
            'image_analysis' => [
                'overall_condition' => 'fair',
                'confidence' => 0.78,
                'detected_issues' => [
                    'screen_scratches' => true,
                    'screen_cracks' => false,
                    'back_glass_damage' => false,
                    'frame_dents' => true,
                    'camera_damage' => false,
                ],
                'notes' => ['Usure visible sur la facade.'],
                'recommended_questions' => ['La camera est-elle nette ?'],
                'source' => 'vision',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.condition_details.screen_condition', 'scratched')
            ->assertJsonPath('data.condition_details.frame_condition', 'dented')
            ->assertJsonPath('data.condition_details.face_id_works', false)
            ->assertJsonPath('data.condition_details.repaired', true)
            ->assertJsonPath('data.image_analysis.source', 'vision')
            ->assertJsonPath('data.currency', 'GNF');

        $this->assertSame(2349000.0, (float) $response->json('data.base_price'));
        $this->assertSame(843900.0, (float) $response->json('data.total_deduction'));
        $this->assertSame(1505100.0, (float) $response->json('data.estimated_price'));

        $deductionLabels = collect($response->json('data.deductions'))->pluck('label')->all();

        $this->assertContains('Batterie < 90%', $deductionLabels);
        $this->assertContains('État rayé', $deductionLabels);
        $this->assertContains('Micro-rayures écran', $deductionLabels);
        $this->assertContains('Châssis enfoncé', $deductionLabels);
        $this->assertContains('Face ID / Touch ID indisponible', $deductionLabels);
        $this->assertContains('Téléphone déjà réparé', $deductionLabels);

        $nextQuestions = $response->json('data.next_questions');
        $this->assertContains('La caméra arrière est-elle nette et sans tache ?', $nextQuestions);
    }

    #[Test]
    public function trade_endpoint_returns_difference_for_target_phone(): void
    {
        TrocPhonePrice::query()->create([
            'model' => 'iPhone 15',
            'storage' => '128GB',
            'base_price' => 600,
        ]);

        $this->actingAs($this->createClientUser(), 'api');

        $response = $this->postJson('/api/v1/troc/trade', [
            'user_price' => 1725600,
            'target_model' => 'iPhone 15',
            'target_storage' => '128GB',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.target_model', 'iPhone 15')
            ->assertJsonPath('data.target_storage', '128GB')
            ->assertJsonPath('data.currency', 'GNF')
            ->assertJsonPath('data.message', 'Tu ajoutes 3 494 400 GNF');

        $this->assertSame(5220000.0, (float) $response->json('data.target_price'));
        $this->assertSame(1725600.0, (float) $response->json('data.user_price'));
        $this->assertSame(3494400.0, (float) $response->json('data.difference'));
    }

    private function createClientUser(): User
    {
        $role = Role::query()->updateOrCreate(
            ['slug' => 'client'],
            [
                'name' => 'Client',
                'description' => 'Rôle client (tests)',
                'is_super_admin' => false,
            ]
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'phone' => '622123456',
            'display_name' => 'Client Troc Test',
            'is_pro' => false,
            'two_factor_enabled' => true,
        ]);
    }
}