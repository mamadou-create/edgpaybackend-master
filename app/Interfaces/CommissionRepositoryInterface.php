<?php

namespace App\Interfaces;

namespace App\Interfaces;

interface CommissionRepositoryInterface
{
    public function getAll();
    public function getByID($id);
    public function getByKey($key);
    public function create(array $data);
    public function update($id, array $data);
    public function updateByKey($key, $value);

}