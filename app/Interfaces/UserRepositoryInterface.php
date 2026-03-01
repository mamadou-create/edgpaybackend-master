<?php

namespace App\Interfaces;

interface UserRepositoryInterface extends CrudInterface
{
    // Ici tu peux ajouter des méthodes spécifiques à UserRepository
    public function getAllRoles();
    public function getAllRolesWithClient();
    public function getAllByUserAssigned(string $asseigned_user);
    public function findByEmail(string $email);
    public function findByPhone(string $phone);
    public function updatePassword(string $id, array $data);
    public function updateStatus(string $id, bool $status);
    public function findByPasswordResetToken($token);
    public function findByActivationToken($token);
    public function findByOtp($token);
}
