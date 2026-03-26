<?php

namespace App\Interfaces;

use Illuminate\Pagination\Paginator;

interface TopupRequestRepositoryInterface extends CrudInterface
{
    public function findByStatus(string $status): iterable;
    public function findByStatusAndPro(string $status, string $proId): iterable;

    public function findByUser(string $userId);

    public function updateStatus(string $id, string $status): bool;

    // Nouvelle méthode pour annuler une demande avec soft delete
    public function cancel(string $id, ?string $reason): bool;

    // Méthodes pour gérer le soft delete
    public function restore(string $id): bool;
    public function forceDelete(string $id): bool;
    public function getTrashed(): iterable;
    public function findTrashedById(string $id): ?object;
    public function findCancelled(): iterable;
    public function canBeCancelled(string $id): bool;

    public function getStatistics(): array;
    public function searchWithFilters(array $filters, int $perPage = 15): Paginator;
    public function searchWithFiltersAndWhere(string $proId, array $filters, int $perPage = 15): \Illuminate\Pagination\LengthAwarePaginator;
    public function countByUserAndFilters(string $proId, array $filters): int;
    public function idempotencyKeyExists(string $idempotencyKey): bool;
    public function getPendingRequestsForUser(string $proId);
    public function getRechargesProForSubAdmin(string $subAdminId, int $perPage = 15, array $filters = []): \Illuminate\Pagination\Paginator;
    public function getRechargesProStatusForSubAdmin(string $status, string $subAdminId, int $perPage = 15): \Illuminate\Pagination\Paginator;
}
