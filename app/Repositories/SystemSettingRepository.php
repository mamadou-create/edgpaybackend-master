<?php


namespace App\Repositories;

use App\Interfaces\SystemSettingRepositoryInterface;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class SystemSettingRepository implements SystemSettingRepositoryInterface
{
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
        
        if ($setting) {
            $setting->update(['value' => $value]);
            // $this->clearCache();
            return $setting;
        }
        
        return null;
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