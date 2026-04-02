<?php

namespace Tests\Feature\Admin;

use App\Mail\AdminDocumentDispatchMail;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminDocumentDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_document_by_email(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $response = $this->postJson('/api/v1/admin-document-dispatch', [
            'channel' => 'email',
            'document_type' => 'purchase_order',
            'document_title' => 'Bon de commande',
            'document_number' => 'BC-2026-0001',
            'message' => 'Veuillez trouver ci-joint votre bon de commande.',
            'recipient_email' => 'client@example.test',
            'recipient_name' => 'Client Test',
            'pdf_name' => 'bon_commande_BC-2026-0001.pdf',
            'pdf_b64' => base64_encode('%PDF-test%'),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.channel', 'email');

        Mail::assertSent(AdminDocumentDispatchMail::class, function (AdminDocumentDispatchMail $mail): bool {
            return $mail->hasTo('client@example.test');
        });
    }

    public function test_admin_can_send_document_by_whatsapp(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'api');

        $this->mock(WhatsAppCloudApiService::class, function ($mock): void {
            $mock->shouldReceive('sendDocumentMessage')
                ->once()
                ->andReturn([
                    'success' => true,
                    'status' => 200,
                    'data' => ['messages' => [['id' => 'wamid.mock']]],
                    'media_id' => 'media.mock',
                ]);
        });

        $response = $this->postJson('/api/v1/admin-document-dispatch', [
            'channel' => 'whatsapp',
            'document_type' => 'purchase_order',
            'document_title' => 'Bon de commande',
            'document_number' => 'BC-2026-0002',
            'message' => 'Veuillez trouver ci-joint votre bon de commande.',
            'recipient_phone' => '622000000',
            'recipient_name' => 'Client WhatsApp',
            'pdf_name' => 'bon_commande_BC-2026-0002.pdf',
            'pdf_b64' => base64_encode('%PDF-test%'),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.channel', 'whatsapp');
    }
}