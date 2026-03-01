<?php
// app/Interfaces/PaymentRepositoryInterface.php

namespace App\Interfaces;

use App\Models\Payment;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface PaymentRepositoryInterface
{
    /**
     * Trouver un paiement par son ID
     */
    public function findById(string $id): ?Payment;

    /**
     * Trouver un paiement par sa référence externe (Djomy)
     */
    public function findByExternalReference(string $externalReference): ?Payment;

    /**
     * Trouver un paiement par sa référence marchand
     */
    public function findByMerchantReference(string $merchantReference): ?Payment;

    /**
     * Trouver un paiement par son ID ou référence
     */
    public function findByIdOrReference(string $idOrReference): ?Payment;

    /**
     * Récupérer tous les paiements avec pagination
     */
    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Récupérer les paiements par statut
     */
    public function getByStatus(string $status, int $perPage = 15): LengthAwarePaginator;

    /**
     * Récupérer les paiements par méthode de paiement
     */
    public function getByPaymentMethod(string $paymentMethod, int $perPage = 15): LengthAwarePaginator;

    /**
     * Récupérer les paiements par utilisateur
     */
    public function getByUser(string $userId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Récupérer les paiements entre deux dates
     */
    public function getByDateRange(string $startDate, string $endDate, int $perPage = 15): LengthAwarePaginator;

    /**
     * Créer un nouveau paiement
     */
    public function create(array $data): Payment;

    /**
     * Mettre à jour un paiement
     */
    public function update(string $id, array $data): bool;

    /**
     * Mettre à jour le statut d'un paiement
     */
    public function updateStatus(string $id, string $status): bool;

    /**
     * Mettre à jour la référence externe d'un paiement
     */
    public function updateExternalReference(string $id, string $externalReference): bool;

    /**
     * Supprimer un paiement (soft delete)
     */
    public function delete(string $id): bool;

    /**
     * Restaurer un paiement supprimé
     */
    public function restore(string $id): bool;

    /**
     * Supprimer définitivement un paiement
     */
    public function forceDelete(string $id): bool;

    /**
     * Récupérer les statistiques des paiements
     */
    public function getStats(array $filters = []): array;

    /**
     * Récupérer l'historique des statuts d'un paiement
     */
    public function getStatusHistory(string $paymentId): array;

    /**
     * Rechercher des paiements
     */
    public function search(string $query, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Récupérer les paiements échoués nécessitant une nouvelle tentative
     */
    public function getFailedPaymentsForRetry(int $hours = 24): Collection;

    /**
     * Compter les paiements par statut
     */
    public function countByStatus(string $status): int;

    /**
     * Compter les paiements par méthode
     */
    public function countByPaymentMethod(string $paymentMethod): int;

    /**
     * Récupérer le montant total des paiements
     */
    public function getTotalAmount(array $filters = []): float;

    /**
     * Vérifier si une référence marchand existe
     */
    public function merchantReferenceExists(string $merchantReference): bool;
}