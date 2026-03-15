<?php


namespace App\Repositories;

use App\Interfaces\SystemSettingRepositoryInterface;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class SystemSettingRepository implements SystemSettingRepositoryInterface
{
    private function getDefaultSettingDefinition(string $key): ?array
    {
        $definitions = [
            'client_payments_enabled' => [
                'type' => 'boolean',
                'group' => 'payments',
                'description' => 'Active/désactive les paiements pour les clients',
                'order' => 1,
            ],
            'pro_payments_enabled' => [
                'type' => 'boolean',
                'group' => 'payments',
                'description' => 'Active/désactive les paiements pour les utilisateurs PRO',
                'order' => 2,
            ],
            'sub_admin_payments_enabled' => [
                'type' => 'boolean',
                'group' => 'payments',
                'description' => 'Active/désactive les paiements pour les sous-administrateurs',
                'order' => 3,
            ],
            'maintenance_mode' => [
                'type' => 'boolean',
                'group' => 'system',
                'description' => 'Mode maintenance',
                'order' => 10,
            ],
            'max_transaction_amount' => [
                'type' => 'integer',
                'group' => 'limits',
                'description' => 'Montant maximum par transaction (en unité monétaire)',
                'order' => 20,
            ],
            'max_client_wallet_balance' => [
                'type' => 'integer',
                'group' => 'limits',
                'description' => 'Solde maximum autorisé pour un wallet client',
                'order' => 21,
            ],
            'pro_gain_percent_on_client_cashout' => [
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de gain du PRO sur chaque retrait cash client',
                'order' => 30,
            ],
            'pro_gain_percent_on_client_deposit' => [
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de gain du PRO sur chaque dépôt/recharge client',
                'order' => 31,
            ],
            'client_cashout_fee_percent' => [
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de frais prélevé sur le client lors d\'un retrait cash',
                'order' => 32,
            ],
            'client_to_client_transfer_fee_percent_above_1000000' => [
                'type' => 'float',
                'group' => 'payments',
                'description' => 'Pourcentage de frais appliqué aux transferts client->client strictement supérieurs à 1 000 000 GNF',
                'order' => 33,
            ],
            'chatbot_intent_keywords_prepaid_bill' => [
                'type' => 'string',
                'group' => 'chatbot',
                'description' => 'Mots-clés chatbot pour ouvrir le paiement facture prépayée (séparés par des virgules)',
                'order' => 40,
            ],
            'chatbot_intent_keywords_postpaid_bill' => [
                'type' => 'string',
                'group' => 'chatbot',
                'description' => 'Mots-clés chatbot pour ouvrir le paiement facture postpayée (séparés par des virgules)',
                'order' => 41,
            ],
        ];

        return $definitions[$key] ?? null;
    }

    public function getAll()
    {
        // return Cache::remember('system_settings_all', 3600, function () {
            
        // });
        return SystemSetting::active()->orderBy('order')->get();
    }

    public function getByID($id)
    {
        return SystemSetting::find($id);
    }

    public function getByKey($key)
    {
        return SystemSetting::where('key', $key)->first();
    }

    public function getSettingsByGroup($group)
    {
        // return Cache::remember("system_settings_group_{$group}", 3600, function () use ($group) {
          
        // });

          return SystemSetting::active()->byGroup($group)->orderBy('order')->get();
    }

    public function create(array $details)
    {
        $setting = SystemSetting::create($details);
        // $this->clearCache();
        return $setting;
    }

    public function update($id, array $details)
    {
        $setting = SystemSetting::find($id);
        
        if ($setting) {
            $setting->update($details);
            // $this->clearCache();
            return $setting;
        }
        
        return null;
    }

    public function updateByKey($key, $value)
    {
        $setting = SystemSetting::where('key', $key)->first();

        if (!$setting) {
            $definition = $this->getDefaultSettingDefinition((string) $key);
            if (!$definition) {
                return null;
            }

            $setting = SystemSetting::create([
                'key' => (string) $key,
                'value' => (string) $value,
                'type' => $definition['type'],
                'group' => $definition['group'],
                'description' => $definition['description'],
                'is_active' => true,
                'is_editable' => true,
                'order' => $definition['order'],
            ]);

            return $setting;
        }

        $setting->update(['value' => $value]);
        // $this->clearCache();
        return $setting;
    }

    public function updateMultiple(array $settings)
    {
        $results = [];
        
        foreach ($settings as $key => $value) {
            $results[$key] = $this->updateByKey($key, $value);
        }
        
        return $results;
    }

    public function getPaymentSettings()
    {
        $settings = $this->getSettingsByGroup('payments');
        
        $formattedSettings = [];
        $activeCount = 0;
        
        foreach ($settings as $setting) {
            $formattedSettings[$setting->key] = [
                'value' => $setting->formatted_value,
                'type' => $setting->type,
                'description' => $setting->description,
                'is_editable' => $setting->is_editable,
            ];
            
            if ($setting->formatted_value) {
                $activeCount++;
            }
        }
        
        // Force JSON object shape for `settings` when empty.
        // Without this, PHP encodes an empty array as `[]` (JSON array),
        // which can break clients expecting a map/object.
        $settingsPayload = empty($formattedSettings) ? (object) [] : $formattedSettings;

        return [
            'settings' => $settingsPayload,
            'summary' => [
                'total' => count($settings),
                'active' => $activeCount,
                'inactive' => count($settings) - $activeCount,
                'status' => $this->getStatusText($activeCount, count($settings)),
            ]
        ];
    }

    private function getStatusText($activeCount, $total)
    {
        if ($total == 0) return 'Aucun paramètre';
        if ($activeCount == $total) return 'Tout actif';
        if ($activeCount >= 2) return 'Partiellement actif';
        if ($activeCount == 1) return 'Limité';
        return 'Inactif';
    }

    private function clearCache()
    {
        Cache::forget('system_settings_all');
        Cache::forget('system_settings_group_payments');
    }

}