<?php

namespace App\Repositories;

use App\Enums\RoleEnum;
use App\Interfaces\AnnouncementRepositoryInterface;
use App\Models\Announcement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class AnnouncementRepository implements AnnouncementRepositoryInterface
{
    protected $model;

    public function __construct(Announcement $announcement)
    {
        $this->model = $announcement;
    }

    public function all(): Collection
    {
        return $this->model->with('author')->latest()->get();
    }

    public function getLatestAnnouncements($role, $userId, $status = null, $limit = 20)
    {
        $query = Announcement::query()
            ->with(['author', 'readers' => function ($query) use ($userId) {
                $query->where('user_id', $userId)->withPivot('read_at');
            }])
            ->orderBy('created_at', 'desc');

        if ($role !== RoleEnum::SUPER_ADMIN) {
            $query->where(function ($q) use ($role) {
                $q->whereJsonContains('target_roles', $role)
                    ->orWhereNull('target_roles')
                    ->orWhere('target_roles', '[]');
            });
        }

        // Filtre par statut de lecture
        if ($status === 'unread') {
            $query->whereDoesntHave('readers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        } elseif ($status === 'read') {
            $query->whereHas('readers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        }

        return $query->take($limit)->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->with('author')->latest()->paginate($perPage);
    }

    public function find(string $id): ?Announcement
    {
        return $this->model->with('author')->find($id);
    }

    public function create(array $data): Announcement
    {
        return $this->model->create($data);
    }

    public function update(string $id, array $data): bool
    {
        $announcement = $this->find($id);

        if (!$announcement) {
            return false;
        }

        return $announcement->update($data);
    }

    public function delete(string $id): bool
    {
        $announcement = $this->find($id);

        if (!$announcement) {
            return false;
        }

        return $announcement->delete();
    }

    public function forUserRole(string $role, ?string $userId = null): Collection
    {
        $query = $this->model->with('author')->latest();

        $query->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhere('target_roles', '[]')
                ->orWhereJsonContains('target_roles', $role);
        });

        if ($userId) {
            $query->with(['readers' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }]);
        }

        return $query->get();
    }

    public function paginateForUserRole(string $role, int $perPage = 15, ?string $userId = null): LengthAwarePaginator
    {
        $query = $this->model->with('author')->latest();

        $query->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhere('target_roles', '[]')
                ->orWhereJsonContains('target_roles', $role);
        });

        if ($userId) {
            $query->with(['readers' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }]);
        }

        return $query->paginate($perPage);
    }

    public function markAsRead(string $announcementId, string $userId): void
    {
        $announcement = $this->find($announcementId);

        if ($announcement) {
            $announcement->markAsRead($userId);
        }
    }

    public function markAllAsRead(string $role, string $userId): int
    {
        $unreadAnnouncements = $this->model->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhere('target_roles', '[]')
                ->orWhereJsonContains('target_roles', $role);
        })
            ->whereDoesntHave('readers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->get();

        foreach ($unreadAnnouncements as $announcement) {
            $announcement->markAsRead($userId);
        }

        return $unreadAnnouncements->count();
    }

    public function getUnreadCount(string $role, string $userId): int
    {
        return $this->model->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhere('target_roles', '[]')
                ->orWhereJsonContains('target_roles', $role);
        })
            ->whereDoesntHave('readers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->count();
    }

    public function getStats(string $role, string $userId): array
    {
        $total = $this->model->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhere('target_roles', '[]')
                ->orWhereJsonContains('target_roles', $role);
        })->count();

        $unread = $this->getUnreadCount($role, $userId);

        $recent = $this->model->with('author')
            ->where(function ($q) use ($role) {
                $q->whereNull('target_roles')
                    ->orWhere('target_roles', '[]')
                    ->orWhereJsonContains('target_roles', $role);
            })
            ->latest()
            ->take(5)
            ->get();

        return [
            'total' => $total,
            'unread' => $unread,
            'read' => $total - $unread,
            'recent' => $recent,
        ];
    }
}
