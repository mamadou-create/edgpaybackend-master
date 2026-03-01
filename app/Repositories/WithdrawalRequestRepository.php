<?php

namespace App\Repositories;

use App\Enums\RoleEnum;
use App\Helpers\HelperStatus;
use App\Interfaces\WithdrawalRequestRepositoryInterface;
use App\Models\WithdrawalRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class WithdrawalRequestRepository implements WithdrawalRequestRepositoryInterface
{
    protected $model;

    public function __construct(WithdrawalRequest $model)
    {
        $this->model = $model;
    }

    private function applyRoleFilter($query)
    {
        $user = Auth::user();

        // Si l'utilisateur est un super-admin, on ne filtre pas
        if ($user->role->slug === RoleEnum::SUPER_ADMIN) {
            return $query;
        }

        // Si l'utilisateur est un sous-admin, on filtre par assigned_user
        if (in_array($user->role->slug, [
            RoleEnum::SUPPORT_ADMIN,
            RoleEnum::FINANCE_ADMIN,
            RoleEnum::COMMERCIAL_ADMIN
        ])) {
            return $query->whereHas('user', function ($q) use ($user) {
                $q->where('assigned_user', $user->id);
            });
        }

        // Pour les autres rôles (comme PRO), on ne retourne que leurs propres demandes
        // Note: normalement, les pros ne devraient pas accéder à ces méthodes, mais au cas où
        return $query->where('user_id', $user->id);
    }

    public function getAll()
    {
        $query = $this->model->with(['wallet', 'user']);
        $query = $this->applyRoleFilter($query);
        return $query->latest()->get();
    }

    public function getById(string $id)
    {
        return $this->model->with(['wallet', 'user'])->find($id);
    }

    public function create(array $data)
    {
        return $this->model->create($data);
    }

    public function update(string $id, array $data)
    {
        $withdrawalRequest = $this->getById($id);
        return $withdrawalRequest ? $withdrawalRequest->update($data) : false;
    }

    public function delete(string $id)
    {
        $withdrawalRequest = $this->getById($id);
        return $withdrawalRequest ? $withdrawalRequest->delete() : false;
    }

    public function getByUserId(string $userId)
    {
        return $this->model->with(['wallet'])
            ->where('user_id', $userId)
            ->latest()
            ->get();
    }

    public function getByWalletId(string $walletId)
    {
        return $this->model->with(['user'])
            ->where('wallet_id', $walletId)
            ->latest()
            ->get();
    }

    public function getPending()
    {
        $query = $this->model->with(['wallet', 'user'])->pending();
        $query = $this->applyRoleFilter($query);
        return $query->latest()->get();
    }

    public function getQuery()
    {
        return $this->model->query();
    }

    public function getByStatus(string $status)
    {
        $query = $this->model->with(['wallet', 'user'])->where('status', $status);
        $query = $this->applyRoleFilter($query);
        return $query->latest()->get();
    }

    public function getByProvider(string $provider)
    {
        $query = $this->model->with(['wallet', 'user'])->where('provider', $provider);
        $query = $this->applyRoleFilter($query);
        return $query->latest()->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->with(['wallet', 'user']);
        $query = $this->applyRoleFilter($query);
        return $query->latest()->paginate($perPage);
    }

    public function paginateByUser(string $userId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->with(['wallet'])
            ->where('user_id', $userId)
            ->latest()
            ->paginate($perPage);
    }

    public function paginateByStatus(string $status, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->with(['wallet', 'user'])->where('status', $status);
        $query = $this->applyRoleFilter($query);
        return $query->latest()->paginate($perPage);
    }

    public function getStats(): array
    {
        $query = $this->model->query();
        $query = $this->applyRoleFilter($query);

        return [
            'total' => $query->count(),
            'pending' => $query->clone()->pending()->count(),
            'approved' => $query->clone()->approved()->count(),
            'rejected' => $query->clone()->rejected()->count(),
            'cancelled' => $query->clone()->where('status', HelperStatus::CANCELLED)->count(),
            'processing' => $query->clone()->where('status', HelperStatus::PENDING)->count(),
            'total_amount' => $query->clone()->sum('amount'),
            'pending_amount' => $query->clone()->pending()->sum('amount'),
            'approved_amount' => $query->clone()->approved()->sum('amount'),
            'rejected_amount' => $query->clone()->rejected()->sum('amount'),
            'cancelled_amount' => $query->clone()->where('status', HelperStatus::CANCELLED)->sum('amount'),
            'average_amount' => $query->clone()->avg('amount') ?? 0,
            'min_amount' => $query->clone()->min('amount') ?? 0,
            'max_amount' => $query->clone()->max('amount') ?? 0,
        ];
    }

    public function getUserStats(string $userId): array
    {
        $userRequests = $this->model->where('user_id', $userId);

        return [
            'total' => $userRequests->count(),
            'pending' => $userRequests->pending()->count(),
            'approved' => $userRequests->approved()->count(),
            'rejected' => $userRequests->rejected()->count(),
            'cancelled' => $userRequests->where('status', HelperStatus::CANCELLED)->count(),
            'total_amount' => $userRequests->sum('amount'),
            'pending_amount' => $userRequests->pending()->sum('amount'),
            'approved_amount' => $userRequests->approved()->sum('amount'),
            'rejected_amount' => $userRequests->rejected()->sum('amount'),
            'average_amount' => $userRequests->avg('amount') ?? 0,
        ];
    }

    public function getDailyStats(?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        $query = $this->model->whereDate('created_at', $date);
        $query = $this->applyRoleFilter($query);

        return [
            'date' => $date,
            'count' => $query->count(),
            'amount' => $query->sum('amount'),
            'pending' => $query->clone()->pending()->count(),
            'approved' => $query->clone()->approved()->count(),
            'rejected' => $query->clone()->rejected()->count(),
            'cancelled' => $query->clone()->where('status', HelperStatus::CANCELLED)->count(),
            'pending_amount' => $query->clone()->pending()->sum('amount'),
            'approved_amount' => $query->clone()->approved()->sum('amount'),
        ];
    }

    public function getRecent(int $days = 30)
    {
        $query = $this->model->with(['wallet', 'user'])
            ->where('created_at', '>=', now()->subDays($days));
        $query = $this->applyRoleFilter($query);
        return $query->latest()->get();
    }

    public function countByStatus(string $status): int
    {
        $query = $this->model->where('status', $status);
        $query = $this->applyRoleFilter($query);
        return $query->count();
    }

    public function countByUser(string $userId): int
    {
        return $this->model->where('user_id', $userId)->count();
    }

    public function getTotalAmountByStatus(string $status): int
    {
        $query = $this->model->where('status', $status);
        $query = $this->applyRoleFilter($query);
        return $query->sum('amount');
    }

    public function getTotalAmountByUser(string $userId): int
    {
        return $this->model->where('user_id', $userId)->sum('amount');
    }

    public function search(array $criteria)
    {
        $query = $this->model->with(['wallet', 'user']);

        if (isset($criteria['user_id'])) {
            $query->where('user_id', $criteria['user_id']);
        }

        if (isset($criteria['wallet_id'])) {
            $query->where('wallet_id', $criteria['wallet_id']);
        }

        if (isset($criteria['status'])) {
            $query->where('status', $criteria['status']);
        }

        if (isset($criteria['provider'])) {
            $query->where('provider', $criteria['provider']);
        }

        if (isset($criteria['min_amount'])) {
            $query->where('amount', '>=', $criteria['min_amount']);
        }

        if (isset($criteria['max_amount'])) {
            $query->where('amount', '<=', $criteria['max_amount']);
        }

        if (isset($criteria['start_date'])) {
            $query->where('created_at', '>=', $criteria['start_date']);
        }

        if (isset($criteria['end_date'])) {
            $query->where('created_at', '<=', $criteria['end_date']);
        }

        if (isset($criteria['search_term'])) {
            $searchTerm = $criteria['search_term'];
            $query->where(function($q) use ($searchTerm) {
                $q->where('description', 'like', "%{$searchTerm}%")
                  ->orWhere('id', 'like', "%{$searchTerm}%")
                  ->orWhereHas('user', function($userQuery) use ($searchTerm) {
                      $userQuery->where('name', 'like', "%{$searchTerm}%")
                               ->orWhere('email', 'like', "%{$searchTerm}%");
                  });
            });
        }

        $query = $this->applyRoleFilter($query);
        return $query->latest()->get();
    }

    public function getExpired(int $days = 7)
    {
        $query = $this->model->with(['wallet', 'user'])
            ->pending()
            ->where('created_at', '<=', now()->subDays($days));
        $query = $this->applyRoleFilter($query);
        return $query->latest()->get();
    }

    public function updateStatus(string $id, string $status, array $additionalData = []): bool
    {
        $data = array_merge(['status' => $status], $additionalData);
        
        if ($status === HelperStatus::APPROVED || $status === HelperStatus::REJECTED) {
            $data['processed_at'] = now();
        }

        return $this->update($id, $data);
    }

    public function getWithRelations(array $relations = ['user', 'wallet'])
    {
        $query = $this->model->with($relations);
        $query = $this->applyRoleFilter($query);
        return $query->latest()->get();
    }

    public function getApprovedAmountByPeriod(string $startDate, string $endDate): int
    {
        $query = $this->model->approved()
            ->whereBetween('created_at', [$startDate, $endDate]);
        $query = $this->applyRoleFilter($query);
        return $query->sum('amount');
    }
}