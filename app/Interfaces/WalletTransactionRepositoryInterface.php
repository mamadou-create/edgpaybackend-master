<?php

namespace App\Interfaces;

use App\Models\WalletTransaction;
use Illuminate\Database\Query\Builder; 

interface WalletTransactionRepositoryInterface
{
    /**
     * Récupérer toutes les transactions
     */
    public function getAll(): iterable;

    /**
     * Récupérer une transaction par son ID
     */
    public function getById(string $id): ?WalletTransaction;

    /**
     * Créer une transaction
     */
    public function create(array $data): ?WalletTransaction;

    /**
     * Supprimer une transaction
     */
    public function delete(string $id): bool;

    /**
     * Récupérer toutes les transactions d’un wallet
     */
    public function findByWallet(string $walletId): iterable;

    /**
     * Récupérer toutes les transactions d’un utilisateur
     */
    public function findByUser(string $userId): iterable;

    /**
     * Récupérer les transactions par type (ex: deposit, withdraw, commission…)
     */
    public function findByType(string $type): iterable;

    /**
     * Récupérer le query builder pour des requêtes personnalisées
     */
    public function getQuery();
}
