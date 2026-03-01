<?php

namespace App\Interfaces;

namespace App\Interfaces;

interface SystemSettingRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function getByKey($key);
    public function getSettingsByGroup($group);
    public function create(array $details);
    public function update($id, array $details);
    public function updateByKey($key, $value);
    public function updateMultiple(array $settings);
    public function getPaymentSettings();
}