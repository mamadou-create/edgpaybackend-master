<?php

namespace App\Enums;

enum CommissionEnum: string
{
    const SOUS_ADMIN = 'SOUS-ADMIN';
    const EDG = 'EDG';
    const GSS = 'GSS';


    public static function values(): array
    {
        // Récupérer les cases
        $cases = array_column(self::cases(), 'value');

        // Ajouter les constantes
        $constants = [
            self::SOUS_ADMIN,
            self::EDG,
            self::GSS,
        ];

        return array_merge($cases, $constants);
    }
}
