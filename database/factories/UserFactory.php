<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Minimal role required by schema.
        // Tests can override `role_id` or use explicit states if needed.
        $defaultRole = Role::query()->firstOrCreate(
            ['slug' => 'client'],
            [
                'name' => 'Client',
                'description' => 'Rôle client (tests)',
                'is_super_admin' => false,
            ]
        );

        return [
            'id' => (string) Str::uuid(),
            'email' => fake()->unique()->safeEmail(),
            // Format validé côté modèle: /^(62|65|66)[0-9]{7}$/
            'phone' => fake()->unique()->numerify('62#######'),
            'display_name' => fake()->name(),
            'email_verified_at' => now(),
            'status' => true,
            'is_pro' => false,
            'solde_portefeuille' => 0,
            'commission_portefeuille' => 0,
            'role_id' => $defaultRole->id,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
