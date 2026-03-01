<?php

namespace App\Interfaces;

interface CompteurRepositoryInterface extends CrudInterface
{
    public function findByTypeCompteur(string $typeCompteur);
    public function checkCompteurExists(string $compteur, string $userId): bool;
    public function findCompteurByClient( string $clientId);
}
