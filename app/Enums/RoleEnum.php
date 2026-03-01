<?php

namespace App\Enums;

enum RoleEnum: string
{
    const SUPER_ADMIN = 'super_admin';
    const SUPPORT_ADMIN = 'support_admin';
    const FINANCE_ADMIN = 'finance_admin';
    const COMMERCIAL_ADMIN = 'commercial_admin';
    const PRO = 'pro';
    case CLIENT = 'client';
    case API_CLIENT = 'api_client';


    public static function values(): array
    {
        // Récupérer les cases
        $cases = array_column(self::cases(), 'value');

        // Ajouter les constantes
        $constants = [
            self::SUPER_ADMIN,
            self::SUPPORT_ADMIN,
            self::FINANCE_ADMIN,
            self::COMMERCIAL_ADMIN,
            self::PRO,
            self::CLIENT,
        ];

        return array_merge($cases, $constants);
    }
}
