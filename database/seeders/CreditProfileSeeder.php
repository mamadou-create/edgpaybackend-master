<?php

namespace Database\Seeders;

use App\Models\CreditProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CreditProfileSeeder extends Seeder
{
    /**
     * Initialise les profils de crédit pour tous les utilisateurs existants
     * qui n'en ont pas encore.
     */
    public function run(): void
    {
        $count = 0;

        User::doesntHave('creditProfile')->get()->each(function (User $user) use (&$count) {
            CreditProfile::create([
                'id'                => Str::uuid(),
                'user_id'           => $user->id,
                'credit_limite'     => 0,
                'credit_disponible' => 0,
                'score_fiabilite'   => 100,
                'niveau_risque'     => 'faible',
            ]);
            $count++;
        });

        $this->command->info("$count profil(s) de crédit initialisé(s).");
    }
}
