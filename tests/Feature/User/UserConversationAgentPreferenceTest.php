<?php

namespace Tests\Feature\User;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserConversationAgentPreferenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_update_endpoint_persists_default_conversational_agent(): void
    {
        $role = Role::query()->updateOrCreate(
            ['slug' => 'client'],
            [
                'name' => 'Client',
                'description' => 'Rôle client (tests)',
                'is_super_admin' => false,
            ]
        );

        $user = User::factory()->create([
            'role_id' => $role->id,
            'phone' => '622100001',
            'display_name' => 'Preference Test',
            'default_conversational_agent' => null,
        ]);

        $this->actingAs($user, 'api');

        $response = $this->putJson('/api/v1/users/' . $user->id, [
            'display_name' => 'Preference Test Updated',
            'email' => $user->email,
            'phone' => $user->phone,
            'default_conversational_agent' => 'expert',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.default_conversational_agent', 'expert');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'default_conversational_agent' => 'expert',
        ]);
    }
}