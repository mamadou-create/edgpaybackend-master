<?php
// database/seeders/SystemSettingSeeder.php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run()
    {
        $settings = [
            [
                'key' => 'client_payments_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'payments',
                'description' => 'Active/désactive les paiements pour les clients',
                'order' => 1,
            ],
            [
                'key' => 'pro_payments_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'payments',
                'description' => 'Active/désactive les paiements pour les utilisateurs PRO',
                'order' => 2,
            ],
            [
                'key' => 'sub_admin_payments_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'payments',
                'description' => 'Active/désactive les paiements pour les sous-administrateurs',
                'order' => 3,
            ],
            [
                'key' => 'maintenance_mode',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'system',
                'description' => 'Mode maintenance',
                'order' => 10,
            ],
            [
                'key' => 'max_transaction_amount',
                'value' => '1000000',
                'type' => 'integer',
                'group' => 'limits',
                'description' => 'Montant maximum par transaction (en unité monétaire)',
                'order' => 20,
            ],
            [
                'key' => 'pro_gain_percent_on_client_cashout',
                'value' => '0',
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de gain du PRO sur chaque retrait cash client',
                'order' => 30,
            ],
            [
                'key' => 'pro_gain_percent_on_client_deposit',
                'value' => '0',
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de gain du PRO sur chaque dépôt/recharge client',
                'order' => 31,
            ],
            [
                'key' => 'client_cashout_fee_percent',
                'value' => '0',
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de frais prélevé sur le client lors d\'un retrait cash',
                'order' => 32,
            ],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}