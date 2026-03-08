<?php

namespace App\Observers;

use App\Enums\RoleEnum;
use App\Models\CreditProfile;
use App\Models\User;
use Illuminate\Support\Str;

class UserObserver
{
    /**
     * Crée automatiquement un profil de crédit à la création d'un PRO.
     */
    public function created(User $user): void
    {
        $isProAtCreation = (bool) ($user->is_pro ?? false)
            || (string) optional($user->role)->slug === RoleEnum::PRO;

        if (!$isProAtCreation) {
            return;
        }

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
