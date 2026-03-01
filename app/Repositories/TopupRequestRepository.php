<?php

namespace App\Repositories;

use App\Helpers\HelperStatus;
use App\Interfaces\TopupRequestRepositoryInterface;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class TopupRequestRepository implements TopupRequestRepositoryInterface
{
    /**
     * Authenticated User Instance.
     *
     * @var User|null
     */
    public ?User $user;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->user = Auth::guard()->user();
    }

    /**
     * Récupérer toutes les demandes de recharge avec pagination
     */
    public function getAll()
    {
        try {
            return TopupRequest::with(['pro', 'decider'])
                ->latest()
                ->paginate(15);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des demandes de recharge: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Créer une nouvelle demande de recharge
     */
    public function create(array $data): ?TopupRequest
    {
        try {
            // 🔑 Génère la clé si elle n’est pas déjà fournie
            if (!isset($data['idempotency_key'])) {
                $data['idempotency_key'] = $this->generateIdempotencyKey();
            }

            return TopupRequest::create($data);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de la demande de recharge: ' . $e->getMessage());
            throw new \RuntimeException('Erreur lors de la création de la demande de recharge.');
        }
    }

    /**
     * Supprimer une demande de recharge (soft delete)
     */
    public function delete(string $id): bool
    {
        try {
            $topupRequest = TopupRequest::findOrFail($id);
            return $topupRequest->delete();
        } catch (ModelNotFoundException $e) {
            Log::warning("Demande de recharge non trouvée pour suppression, ID: $id");
            throw new ModelNotFoundException("Demande de recharge non trouvée.");
        } catch (\Exception $e) {
            Log::error("Erreur lors de la suppression de la demande de recharge $id: " . $e->getMessage());
            throw new \RuntimeException('Erreur lors de la suppression de la demande.');
        }
    }

    /**
     * Récupérer une demande par son ID
     */
    public function getByID(string $id): ?TopupRequest
    {
        try {
            return TopupRequest::with(['pro', 'decider'])->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return null;
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération de la demande $id: " . $e->getMessage());
            throw new \RuntimeException('Erreur lors de la récupération de la demande.');
        }
    }

    /**
     * Mettre à jour une demande de recharge
     */
    public function update(string $id, array $data): bool
    {
        try {
            $topupRequest = TopupRequest::findOrFail($id);
            return $topupRequest->update($data);
        } catch (ModelNotFoundException $e) {
            Log::warning("Demande de recharge non trouvée pour mise à jour, ID: $id");
            throw new ModelNotFoundException("Demande de recharge non trouvée.");
        } catch (\Exception $e) {
            Log::error("Erreur lors de la mise à jour de la demande $id: " . $e->getMessage());
            throw new \RuntimeException('Erreur lors de la mise à jour de la demande.');
        }
    }

    /**
     * Rechercher les demandes par statut
     */
    public function findByStatus(string $status): iterable
    {
        try {
            return TopupRequest::with(['pro', 'decider'])
                ->where('status', $status)
                ->latest()
                ->get();
        } catch (\Exception $e) {
            Log::error("Erreur lors de la recherche des demandes par statut $status: " . $e->getMessage());
            return collect();
        }
    }

    public function findByStatusAndPro(string $status, string $proId): iterable
    {
        try {
            return TopupRequest::with(['pro', 'decider'])
                ->where('status', $status)
                ->where('pro_id', $proId)
                ->latest()
                ->get();
        } catch (\Exception $e) {
            Log::error("Erreur lors de la recherche des demandes par statut $status et pro_id $proId: " . $e->getMessage());
            return collect();
        }
    }


    /**
     * Rechercher les demandes par utilisateur pro
     */
    // public function findByUser(string $proId)
    // {
    //     try {
    //         return TopupRequest::with(['pro', 'decider'])
    //             ->where('pro_id', $proId)
    //             ->latest()
    //             ->get();
    //     } catch (\Exception $e) {
    //         Log::error("Erreur lors de la recherche des demandes pour l'utilisateur $proId: " . $e->getMessage());
    //         return collect();
    //     }
    // }

    public function findByUser(string $proId)
    {
        try {
            return TopupRequest::with(['pro', 'decider'])
                ->where('pro_id', $proId)
                ->latest()
                ->paginate(15); // ✅ pagination propre
        } catch (\Exception $e) {
            Log::error("Erreur lors de la recherche des demandes pour l'utilisateur $proId: " . $e->getMessage());

            // ✅ retourne une pagination vide en cas d'erreur
            return new \Illuminate\Pagination\LengthAwarePaginator(
                collect([]),
                0,
                15,
                1,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }
    }


    /**
     * Mettre à jour le statut d'une demande
     */
    public function updateStatus(string $id, string $status, ?string $reason = null, ?string $decidedBy = null): bool
    {
        try {
            $updateData = [
                'status' => $status,
            ];

            if ($reason) {
                $updateData['cancellation_reason'] = $reason;
                $updateData['cancelled_at'] = now();
            }


            if ($decidedBy) {
                $updateData['decided_by'] = $decidedBy;
            } elseif ($this->user && in_array($status, HelperStatus::validStatuses())) {
                $updateData['decided_by'] = $this->user->id;
            }

            return TopupRequest::where('id', $id)->update($updateData) > 0;
        } catch (\Exception $e) {
            Log::error("Erreur lors de la mise à jour du statut de la demande $id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Approuver une demande de recharge
     */
    public function approve(string $id, ?string $reason = null): bool
    {
        return $this->updateStatus($id, HelperStatus::APPROVED, $reason);
    }

    /**
     * Rejeter une demande de recharge
     */
    public function reject(string $id, string $reason): bool
    {
        return $this->updateStatus($id, HelperStatus::REJECTED, $reason);
    }

    /**
     * Annuler une demande de recharge
     */
    public function cancel(string $id, ?string $reason = null): bool
    {
        try {
            $topupRequest = TopupRequest::findOrFail($id);

            // Vérifier si la demande peut être annulée
            if (!in_array($topupRequest->status, [HelperStatus::PENDING])) {
                Log::warning("Impossible d'annuler la demande $id - Statut actuel: {$topupRequest->status}");
                return false;
            }

            $updateData = [
                'status' => 'CANCELLED',
                'reason' => $reason,
            ];

            if ($this->user) {
                $updateData['decided_by'] = $this->user->id;
            }

            return $topupRequest->update($updateData);
        } catch (ModelNotFoundException $e) {
            Log::warning("Demande de recharge non trouvée pour annulation, ID: $id");
            return false;
        } catch (\Exception $e) {
            Log::error("Erreur lors de l'annulation de la demande $id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Restaurer une demande supprimée
     */
    public function restore(string $id): bool
    {
        try {
            $topupRequest = TopupRequest::onlyTrashed()->findOrFail($id);
            return $topupRequest->restore();
        } catch (ModelNotFoundException $e) {
            Log::warning("Demande de recharge supprimée non trouvée, ID: $id");
            return false;
        } catch (\Exception $e) {
            Log::error("Erreur lors de la restauration de la demande $id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Supprimer définitivement une demande
     */
    public function forceDelete(string $id): bool
    {
        try {
            $topupRequest = TopupRequest::onlyTrashed()->findOrFail($id);
            return $topupRequest->forceDelete();
        } catch (ModelNotFoundException $e) {
            Log::warning("Demande de recharge supprimée non trouvée pour suppression définitive, ID: $id");
            return false;
        } catch (\Exception $e) {
            Log::error("Erreur lors de la suppression définitive de la demande $id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupérer les demandes supprimées
     */
    public function getTrashed(): iterable
    {
        try {
            return TopupRequest::onlyTrashed()
                ->with(['pro', 'decider'])
                ->latest('deleted_at')
                ->get();
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des demandes supprimées: " . $e->getMessage());
            return collect();
        }
    }

    /**
     * Rechercher une demande supprimée par son ID
     */
    public function findTrashedById(string $id): ?TopupRequest
    {
        try {
            return TopupRequest::onlyTrashed()
                ->with(['pro', 'decider'])
                ->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return null;
        } catch (\Exception $e) {
            Log::error("Erreur lors de la recherche de la demande supprimée $id: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Vérifier si une demande peut être annulée
     */
    public function canBeCancelled(string $id): bool
    {
        try {
            $topupRequest = TopupRequest::select('status')->findOrFail($id);

            // ✅ Seules les demandes en attente peuvent être annulées
            return $topupRequest->status === HelperStatus::PENDING;
        } catch (\Exception $e) {
            logger()->warning("Échec de vérification d'annulation pour la demande $id : " . $e->getMessage());
            return false;
        }
    }


    /**
     * Récupérer les demandes annulées (inclut les soft deleted)
     */
    public function findCancelled(): iterable
    {
        try {
            return TopupRequest::withTrashed()
                ->where('status', HelperStatus::CANCELLED)
                ->latest('cancelled_at')
                ->get();
        } catch (\Exception $e) {
            logger()->error("Erreur lors de la récupération des demandes annulées : " . $e->getMessage());
            return collect();
        }
    }

    /**
     * Récupérer les statistiques des demandes
     */
    public function getStatistics(): array
    {
        try {
            return [
                'total' => TopupRequest::count(),
                'pending' => TopupRequest::where('status', 'PENDING')->count(),
                'approved' => TopupRequest::where('status', 'APPROVED')->count(),
                'rejected' => TopupRequest::where('status', 'REJECTED')->count(),
                'cancelled' => TopupRequest::where('status', 'CANCELLED')->count(),
                'total_amount' => TopupRequest::where('status', 'APPROVED')->sum('amount'),
            ];
        } catch (\Exception $e) {
            Log::error("Erreur lors du calcul des statistiques: " . $e->getMessage());
            return [
                'total' => 0,
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0,
                'cancelled' => 0,
                'total_amount' => 0,
            ];
        }
    }

    /**
     * Rechercher les demandes avec filtres avancés
     */
    public function searchWithFilters(array $filters, int $perPage = 15): \Illuminate\Pagination\Paginator
    {
        try {
            $query = TopupRequest::with(['pro', 'decider']);

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['kind'])) {
                $query->where('kind', $filters['kind']);
            }
            if (!empty($filters['pro_id'])) {
                $query->where('pro_id', $filters['pro_id']);
            }
            if (!empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }
            if (!empty($filters['amount_min'])) {
                $query->where('amount', '>=', $filters['amount_min']);
            }
            if (!empty($filters['amount_max'])) {
                $query->where('amount', '<=', $filters['amount_max']);
            }

            return $query->latest()->simplePaginate($perPage);
        } catch (\Exception $e) {
            Log::error("Erreur lors de la recherche avec filtres: " . $e->getMessage());
            return new \Illuminate\Pagination\Paginator(collect(), $perPage); // ✅ Retourne toujours un Paginator
        }
    }

    /**
     * Rechercher les demandes avec filtres avancés
     */

    public function searchWithFiltersAndWhere(string $proId, array $filters, int $perPage = 15): \Illuminate\Pagination\LengthAwarePaginator
    {
        try {
            $query = TopupRequest::with(['pro', 'decider'])
                ->where('pro_id', $proId);

            // Filtres exacts
            $exactFilters = ['status', 'kind', 'pro_id'];
            foreach ($exactFilters as $field) {
                if (!empty($filters[$field])) {
                    $query->where($field, $filters[$field]);
                }
            }

            // Filtres par date
            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            // Filtres par montant
            if (!empty($filters['amount_min'])) {
                $query->where('amount', '>=', $filters['amount_min']);
            }
            if (!empty($filters['amount_max'])) {
                $query->where('amount', '<=', $filters['amount_max']);
            }

            return $query->latest()->paginate($perPage);
        } catch (\Exception $e) {
            Log::error("Erreur lors de la recherche avec filtres: " . $e->getMessage());
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }
    }



    /**
     * Vérifier si une clé d'idempotence existe déjà
     */
    public function idempotencyKeyExists(?string $idempotencyKey): bool
    {
        if (!$idempotencyKey) {
            return false;
        }

        try {
            return TopupRequest::where('idempotency_key', $idempotencyKey)->exists();
        } catch (\Exception $e) {
            Log::error("Erreur lors de la vérification de la clé d'idempotence: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Récupérer les demandes en attente pour un utilisateur spécifique
     */
    public function getPendingRequestsForUser(string $proId)
    {
        try {
            return TopupRequest::where('pro_id', $proId)
                ->where('status', HelperStatus::PENDING)
                ->latest()
                ->get();
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des demandes en attente pour l'utilisateur $proId: " . $e->getMessage());
            return collect();
        }
    }
    // Récupérer les recharges pour un sous-admin

    public function getRechargesProForSubAdmin(string $subAdminId, int $perPage = 15): \Illuminate\Pagination\Paginator
    {
        try {
            return TopupRequest::with(['pro', 'decider'])
                ->whereHas('pro', function ($query) use ($subAdminId) {
                    $query->where('assigned_user', $subAdminId); // filtre sur le sous-admin via la relation Pro -> User
                })
                ->latest()
                ->simplePaginate($perPage);
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des recharges pour le sous-admin $subAdminId : " . $e->getMessage());
            return new \Illuminate\Pagination\Paginator(collect(), $perPage);
        }
    }

    public function getRechargesProStatusForSubAdmin(string $status, string $subAdminId, int $perPage = 15): \Illuminate\Pagination\Paginator
    {
        try {
            return TopupRequest::with(['pro', 'decider'])
                ->where('status', $status)
                ->whereHas('pro', function ($query) use ($subAdminId) {
                    $query->where('assigned_user', $subAdminId);
                })
                ->latest()
                ->simplePaginate($perPage);
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des recharges pour le sous-admin $subAdminId : " . $e->getMessage());
            return new \Illuminate\Pagination\Paginator(collect(), $perPage);
        }
    }




    public function generateIdempotencyKey(): string
    {
        try {
            $date = now()->format('Ymd'); // Format YYYYMMDD
            $counterKey = "topup_counter_{$date}";

            // Incrémenter le compteur dans le cache
            $count = cache()->increment($counterKey);

            // Expiration du compteur à minuit
            cache()->put($counterKey, $count, now()->endOfDay());

            $prefix = "topup-{$date}-{$count}";

            return "{$prefix}-" . uniqid();
        } catch (\Throwable $e) {
            // 🚨 Fallback en cas d'erreur
            return 'topup-' . now()->format('YmdHis') . '-' . uniqid();
        }
    }
}
