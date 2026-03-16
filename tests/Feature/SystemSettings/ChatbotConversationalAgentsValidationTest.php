<?php

namespace Tests\Feature\SystemSettings;

use App\Models\Role;
use App\Models\User;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatbotConversationalAgentsValidationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bulk_update_rejects_real_provider_agent_when_provider_is_not_configured(): void
    {
        config([
            'services.nimba_ai.api_key' => '',
            'services.nimba_ai.providers.claude.api_key' => '',
            'services.nimba_ai.providers.claude.base_url' => 'https://api.anthropic.com/v1/messages',
        ]);

        $this->actingAs($this->createAdminUser(), 'api');

        $response = $this->putJson('/api/v1/system-settings/bulk-update', [
            'settings' => [
                'chatbot_conversational_agents' => json_encode([
                    [
                        'key' => 'claude',
                        'label' => 'Claude Assistant',
                        'description' => 'Agent nuancé.',
                        'provider' => 'claude',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'chatbot_default_conversational_agent' => 'claude',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'Le provider "claude" de l agent "Claude Assistant" n est pas exploitable: configurez au minimum la clé API NIMBA correspondante.'
            ]);

        $this->assertDatabaseMissing('system_settings', [
            'key' => 'chatbot_conversational_agents',
        ]);
    }

    #[Test]
    public function bulk_update_accepts_real_provider_agent_when_provider_is_configured(): void
    {
        config([
            'services.nimba_ai.api_key' => '',
            'services.nimba_ai.providers.gemini.api_key' => 'gemini-test-key',
            'services.nimba_ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
        ]);

        $this->actingAs($this->createAdminUser(), 'api');

        $payload = json_encode([
            [
                'key' => 'gemini',
                'label' => 'Gemini Assistant',
                'description' => 'Agent analytique.',
                'provider' => 'gemini',
                'model' => 'gemini-2.0-flash',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = $this->putJson('/api/v1/system-settings/bulk-update', [
            'settings' => [
                'chatbot_conversational_agents' => $payload,
                'chatbot_default_conversational_agent' => 'gemini',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.failure', 0);

        $this->assertDatabaseHas('system_settings', [
            'key' => 'chatbot_default_conversational_agent',
            'value' => 'gemini',
        ]);
    }

    #[Test]
    public function bulk_update_can_persist_chatbot_web_search_toggle(): void
    {
        $this->actingAs($this->createAdminUser(), 'api');

        $response = $this->putJson('/api/v1/system-settings/bulk-update', [
            'settings' => [
                'chatbot_web_search_enabled' => 'true',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.failure', 0);

        $this->assertDatabaseHas('system_settings', [
            'key' => 'chatbot_web_search_enabled',
            'value' => 'true',
        ]);
    }

    #[Test]
    public function bulk_update_can_persist_chatbot_primary_ai_provider(): void
    {
        config([
            'services.nimba_ai.providers.gemini.api_key' => 'gemini-test-key',
            'services.nimba_ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
        ]);

        $this->actingAs($this->createAdminUser(), 'api');

        $response = $this->putJson('/api/v1/system-settings/bulk-update', [
            'settings' => [
                'chatbot_primary_ai_provider' => 'gemini',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.failure', 0);

        $this->assertDatabaseHas('system_settings', [
            'key' => 'chatbot_primary_ai_provider',
            'value' => 'gemini',
        ]);
    }

    #[Test]
    public function bulk_update_rejects_unconfigured_chatbot_primary_ai_provider(): void
    {
        config([
            'services.nimba_ai.providers.gemini.api_key' => '',
            'services.nimba_ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent',
        ]);

        $this->actingAs($this->createAdminUser(), 'api');

        $response = $this->putJson('/api/v1/system-settings/bulk-update', [
            'settings' => [
                'chatbot_primary_ai_provider' => 'gemini',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'Le provider IA principal "gemini" n est pas exploitable: configurez au minimum la clé API NIMBA correspondante.',
            ]);
    }

    #[Test]
    public function bulk_update_can_persist_chatbot_web_search_provider(): void
    {
        $this->actingAs($this->createAdminUser(), 'api');

        $response = $this->putJson('/api/v1/system-settings/bulk-update', [
            'settings' => [
                'chatbot_web_search_provider' => 'tavily',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.failure', 0);

        $this->assertDatabaseHas('system_settings', [
            'key' => 'chatbot_web_search_provider',
            'value' => 'tavily',
        ]);
    }

    #[Test]
    public function bulk_update_rejects_unsupported_chatbot_web_search_provider(): void
    {
        $this->actingAs($this->createAdminUser(), 'api');

        $response = $this->putJson('/api/v1/system-settings/bulk-update', [
            'settings' => [
                'chatbot_web_search_provider' => 'duckduckgo',
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'Choisissez un provider de recherche web valide: serper ou tavily.',
            ]);
    }

    #[Test]
    public function index_exposes_web_search_runtime_status_for_admin(): void
    {
        config([
            'services.nimba_ai.web_search.enabled' => false,
            'services.nimba_ai.web_search.provider' => 'serper',
            'services.nimba_ai.web_search.api_key' => '',
            'services.nimba_ai.web_search.providers.serper.api_key' => '',
            'services.nimba_ai.web_search.providers.serper.base_url' => 'https://google.serper.dev/search',
        ]);

        SystemSetting::query()->create([
            'key' => 'chatbot_web_search_enabled',
            'value' => 'true',
            'type' => 'boolean',
            'group' => 'chatbot',
            'description' => 'Toggle recherche web NIMBA',
            'is_active' => true,
            'is_editable' => true,
            'order' => 49,
        ]);

        SystemSetting::query()->create([
            'key' => 'chatbot_web_search_provider',
            'value' => 'serper',
            'type' => 'string',
            'group' => 'chatbot',
            'description' => 'Provider recherche web NIMBA',
            'is_active' => true,
            'is_editable' => true,
            'order' => 50,
        ]);

        $this->actingAs($this->createAdminUser(), 'api');

        $response = $this->getJson('/api/v1/system-settings');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'key' => 'chatbot_web_search_enabled',
                'value' => 'true',
            ])
            ->assertJsonFragment([
                'provider' => 'serper',
                'status' => 'missing_api_key',
                'operational' => false,
            ]);
    }

    #[Test]
    public function index_exposes_primary_ai_provider_runtime_status_for_admin(): void
    {
        config([
            'services.nimba_ai.enabled' => true,
            'services.nimba_ai.provider' => 'chatgpt',
            'services.nimba_ai.providers.chatgpt.api_key' => 'openai-test-key',
            'services.nimba_ai.providers.chatgpt.base_url' => 'https://api.openai.com/v1/chat/completions',
        ]);

        SystemSetting::query()->create([
            'key' => 'chatbot_primary_ai_provider',
            'value' => 'chatgpt',
            'type' => 'string',
            'group' => 'chatbot',
            'description' => 'Provider IA principal NIMBA',
            'is_active' => true,
            'is_editable' => true,
            'order' => 51,
        ]);

        $this->actingAs($this->createAdminUser(), 'api');

        $response = $this->getJson('/api/v1/system-settings');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'key' => 'chatbot_primary_ai_provider',
                'value' => 'chatgpt',
            ])
            ->assertJsonFragment([
                'provider' => 'chatgpt',
                'status' => 'ready',
                'operational' => true,
            ]);
    }

    private function createAdminUser(): User
    {
        $role = Role::query()->updateOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Admin',
                'description' => 'Administrateur système',
                'is_super_admin' => false,
            ]
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'phone' => '622777777',
            'display_name' => 'Admin Settings',
        ]);
    }
}