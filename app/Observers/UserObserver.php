<?php

namespace App\Observers;

use App\Models\CreditProfile;
use App\Models\User;
use Illuminate\Support\Str;

class UserObserver
{
    /**
     * Crée automatiquement un profil de crédit à la création de chaque utilisateur.
     */
    public function created(User $user): void
    {
        // Éviter les doublons (ex: seeders qui appellent firstOrCreate)
        if ($user->creditProfile()->exists()) {
            return;
        }

        CreditProfile::create([
            'id'               => Str::uuid(),
            'user_id'          => $user->id,
            'credit_limite'    => 0,
            'credit_disponible' => 0,
            'score_fiabilite'  => 100,
            'niveau_risque'    => 'faible',
        ]);
    }
}
