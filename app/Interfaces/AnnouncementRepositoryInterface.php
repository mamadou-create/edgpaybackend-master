<?php

namespace App\Interfaces;

use App\Models\Announcement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface AnnouncementRepositoryInterface
{
    public function all(): Collection;
    public function getLatestAnnouncements($role, $userId, $status = null, $limit = 20);
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    public function find(string $id): ?Announcement;
    public function create(array $data): Announcement;
    public function update(string $id, array $data): bool;
    public function delete(string $id): bool;
    public function forUserRole(string $role, ?string $userId = null): Collection;
    public function paginateForUserRole(string $role, int $perPage = 15, ?string $userId = null): LengthAwarePaginator;
    public function markAsRead(string $announcementId, string $userId): void;
    public function markAllAsRead(string $role, string $userId);
    public function getUnreadCount(string $role, string $userId);
    public function getStats(string $role, string $userId): array;
}
