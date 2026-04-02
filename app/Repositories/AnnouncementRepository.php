<?php

namespace App\Repositories;

use App\Enums\RoleEnum;
use App\Interfaces\AnnouncementRepositoryInterface;
use App\Models\Announcement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
        return $this->baseQuery()
            ->currentlyVisible()
            ->latest()
            ->get();
    }

    public function getLatestAnnouncements($role, $userId, $status = null, $limit = 20, ?string $lifecycle = null, ?string $moderationStatus = null)
    {
        $query = $this->baseQuery($userId)
            ->orderBy('created_at', 'desc');

        if ($lifecycle === 'expired') {
            $query->whereNotNull('diffusion_ends_at')
                ->where('diffusion_ends_at', '<=', now());
        } elseif ($lifecycle !== 'all') {
            $query->currentlyVisible();
        }

        if ($role !== RoleEnum::SUPER_ADMIN) {
            $query->where(function ($q) use ($role) {
                $q->whereJsonContains('target_roles', $role)
                    ->orWhereNull('target_roles')
                    ->orWhere('target_roles', '[]');
            });
            $query->where(function ($statusQuery) {
                $statusQuery->whereNull('moderation_status')
                    ->orWhere('moderation_status', Announcement::MODERATION_APPROVED);
            });
        } elseif ($moderationStatus !== null && $moderationStatus !== 'all') {
            $query->where('moderation_status', $moderationStatus);
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

    public function getLifecycleCounts($role, $userId): array
    {
        $baseQuery = Announcement::query();

        if ($role !== RoleEnum::SUPER_ADMIN) {
            $baseQuery->where(function ($q) use ($role) {
                $q->whereJsonContains('target_roles', $role)
                    ->orWhereNull('target_roles')
                    ->orWhere('target_roles', '[]');
            });
            $baseQuery->where(function ($statusQuery) {
                $statusQuery->whereNull('moderation_status')
                    ->orWhere('moderation_status', Announcement::MODERATION_APPROVED);
            });
        }

        $allQuery = clone $baseQuery;
        $activeQuery = clone $baseQuery;
        $expiredQuery = clone $baseQuery;

        return [
            'active' => $activeQuery->currentlyVisible()->count(),
            'expired' => $expiredQuery->whereNotNull('diffusion_ends_at')
                ->where('diffusion_ends_at', '<=', now())
                ->count(),
            'all' => $allQuery->count(),
        ];
    }

    public function getModerationCounts($role, $userId): array
    {
        $baseQuery = Announcement::query();

        if ($role !== RoleEnum::SUPER_ADMIN) {
            $baseQuery->where(function ($q) use ($role) {
                $q->whereJsonContains('target_roles', $role)
                    ->orWhereNull('target_roles')
                    ->orWhere('target_roles', '[]');
            });
            $baseQuery->where(function ($statusQuery) {
                $statusQuery->whereNull('moderation_status')
                    ->orWhere('moderation_status', Announcement::MODERATION_APPROVED);
            });
        }

        $pendingQuery = clone $baseQuery;
        $approvedQuery = clone $baseQuery;
        $rejectedQuery = clone $baseQuery;
        $allQuery = clone $baseQuery;

        return [
            'pending' => $pendingQuery->where('moderation_status', Announcement::MODERATION_PENDING)->count(),
            'approved' => $approvedQuery->where(function ($q) {
                $q->whereNull('moderation_status')
                    ->orWhere('moderation_status', Announcement::MODERATION_APPROVED);
            })->count(),
            'rejected' => $rejectedQuery->where('moderation_status', Announcement::MODERATION_REJECTED)->count(),
            'all' => $allQuery->count(),
        ];
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->currentlyVisible()
            ->latest()
            ->paginate($perPage);
    }

    public function find(string $id, ?string $userId = null): ?Announcement
    {
        return $this->baseQuery($userId)->find($id);
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
        $query = $this->baseQuery($userId)->latest();
        if ($role !== RoleEnum::SUPER_ADMIN) {
            $query->currentlyVisible();
        }

        $query->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhere('target_roles', '[]')
                ->orWhereJsonContains('target_roles', $role);
        });
        return $query->get();
    }

    public function paginateForUserRole(string $role, int $perPage = 15, ?string $userId = null): LengthAwarePaginator
    {
        $query = $this->baseQuery($userId)->latest();
        if ($role !== RoleEnum::SUPER_ADMIN) {
            $query->currentlyVisible();
        }

        $query->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhere('target_roles', '[]')
                ->orWhereJsonContains('target_roles', $role);
        });
        return $query->paginate($perPage);
    }

    public function markAsRead(string $announcementId, string $userId): void
    {
        $announcement = $this->find($announcementId, $userId);

        if ($announcement) {
            $announcement->markAsRead($userId);
        }
    }

    public function markAllAsRead(string $role, string $userId): int
    {
        $unreadAnnouncements = $this->model->currentlyVisible()->where(function ($q) use ($role) {
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
        return $this->model->currentlyVisible()->where(function ($q) use ($role) {
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
        $total = $this->model->currentlyVisible()->where(function ($q) use ($role) {
            $q->whereNull('target_roles')
                ->orWhere('target_roles', '[]')
                ->orWhereJsonContains('target_roles', $role);
        })->count();

        $unread = $this->getUnreadCount($role, $userId);

        $recent = $this->baseQuery($userId)->currentlyVisible()
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

    private function baseQuery(?string $userId = null): Builder
    {
        $query = Announcement::query()
            ->with('author')
            ->withCount(['readers', 'likes', 'comments']);

        if ($userId !== null) {
            $query->with([
                'readers' => function ($readerQuery) use ($userId) {
                    $readerQuery->where('user_id', $userId)->withPivot('read_at');
                },
                'likes' => function ($likeQuery) use ($userId) {
                    $likeQuery->where('user_id', $userId);
                },
            ]);
        }

        return $query;
    }
}
