<?php

namespace App\Interfaces;

use App\Models\WithdrawalRequest;
use Illuminate\Pagination\LengthAwarePaginator;

interface WithdrawalRequestRepositoryInterface
{
    /**
     * Récupérer toutes les demandes de retrait
     */
    public function getAll();

    /**
     * Récupérer une demande de retrait par son ID
     */
    public function getById(string $id);

    /**
     * Créer une nouvelle demande de retrait
     */
    public function create(array $data);

    /**
     * Mettre à jour une demande de retrait
     */
    public function update(string $id, array $data);

    /**
     * Supprimer une demande de retrait
     */
    public function delete(string $id);

    /**
     * Récupérer les demandes de retrait d'un utilisateur
     */
    public function getByUserId(string $userId);

    /**
     * Récupérer les demandes de retrait d'un wallet
     */
    public function getByWalletId(string $walletId);

    /**
     * Récupérer les demandes de retrait en attente
     */
    public function getPending();

    /**
     * Récupérer le query builder pour des requêtes personnalisées
     */
    public function getQuery();

    /**
     * Récupérer les demandes par statut
     */
    public function getByStatus(string $status);

    /**
     * Récupérer les demandes par provider
     */
    public function getByProvider(string $provider);

    /**
     * Paginer toutes les demandes de retrait
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    /**
     * Paginer les demandes d'un utilisateur
     */
    public function paginateByUser(string $userId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Paginer les demandes par statut
     */
    public function paginateByStatus(string $status, int $perPage = 15): LengthAwarePaginator;

    /**
     * Récupérer les statistiques globales des demandes de retrait
     */
    public function getStats(): array;

    /**
     * Récupérer les statistiques d'un utilisateur
     */
    public function getUserStats(string $userId): array;

    /**
     * Récupérer les statistiques quotidiennes
     */
    public function getDailyStats(string $date = null): array;

    /**
     * Récupérer les demandes récentes (30 derniers jours par défaut)
     */
    public function getRecent(int $days = 30);

    /**
     * Compter le nombre de demandes par statut
     */
    public function countByStatus(string $status): int;

    /**
     * Compter le nombre de demandes d'un utilisateur
     */
    public function countByUser(string $userId): int;

    /**
     * Récupérer le montant total des demandes par statut
     */
    public function getTotalAmountByStatus(string $status): int;

    /**
     * Récupérer le montant total des demandes d'un utilisateur
     */
    public function getTotalAmountByUser(string $userId): int;

    /**
     * Rechercher des demandes avec plusieurs critères
     */
    public function search(array $criteria);

    /**
     * Récupérer les demandes expirées (en attente depuis plus de X jours)
     */
    public function getExpired(int $days = 7);

    /**
     * Mettre à jour le statut d'une demande
     */
    public function updateStatus(string $id, string $status, array $additionalData = []): bool;

    /**
     * Récupérer les demandes avec leurs relations
     */
    public function getWithRelations(array $relations = ['user', 'wallet', 'processor']);

    /**
     * Récupérer le montant total des retraits approuvés sur une période
     */
    public function getApprovedAmountByPeriod(string $startDate, string $endDate): int;
}