<?php
// app/Services/SystemSettingService.php

namespace App\Services;

use App\Interfaces\SystemSettingRepositoryInterface;

class SystemSettingService
{
    protected $repository;

    public function __construct(SystemSettingRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function getPaymentSettings()
    {
        return $this->repository->getPaymentSettings();
    }

    public function updateSetting($key, $value)
    {
        return $this->repository->updateByKey($key, $value);
    }

    public function updateMultipleSettings(array $settings)
    {
        return $this->repository->updateMultiple($settings);
    }

    public function getSettingsByGroup($group)
    {
        return $this->repository->getSettingsByGroup($group);
    }

    public function isPaymentEnabled($userType)
    {
        $key = match($userType) {
            'client' => 'client_payments_enabled',
            'pro' => 'pro_payments_enabled',
            'sub_admin' => 'sub_admin_payments_enabled',
            default => null,
        };

        if (!$key) return false;

        $setting = $this->repository->getByKey($key);
        return $setting ? (bool) $setting->formatted_value : false;
    }
}