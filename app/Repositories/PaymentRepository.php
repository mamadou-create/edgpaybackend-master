<?php
// app/Repositories/PaymentRepository.php

namespace App\Repositories;

use App\Interfaces\PaymentRepositoryInterface;
use App\Models\Payment;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class PaymentRepository implements PaymentRepositoryInterface
{
    /**
     * Trouver un paiement par son ID
     */
    public function findById(string $id): ?Payment
    {
        return Payment::find($id);
    }

    /**
     * Trouver un paiement par sa référence externe (Djomy)
     */
    public function findByExternalReference(string $externalReference): ?Payment
    {
        return Payment::where('external_reference', $externalReference)->first();
    }

    /**
     * Trouver un paiement par sa référence marchand
     */
    public function findByMerchantReference(string $merchantReference): ?Payment
    {
        return Payment::where('merchant_payment_reference', $merchantReference)->first();
    }

    /**
     * Trouver un paiement par son ID ou référence
     */
    public function findByIdOrReference(string $idOrReference): ?Payment
    {
        return Payment::where('id', $idOrReference)
            ->orWhere('external_reference', $idOrReference)
            ->orWhere('merchant_payment_reference', $idOrReference)
            ->first();
    }

    /**
     * Récupérer tous les paiements avec pagination et filtres
     */
    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Payment::with('user');

        // Appliquer les filtres en utilisant la même méthode que pour search
        $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }


    /**
     * Récupérer les paiements par statut
     */
    public function getByStatus(string $status, int $perPage = 15): LengthAwarePaginator
    {
        return Payment::where('status', $status)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Récupérer les paiements par méthode de paiement
     */
    public function getByPaymentMethod(string $paymentMethod, int $perPage = 15): LengthAwarePaginator
    {
        return Payment::where('payment_method', $paymentMethod)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Récupérer les paiements par utilisateur
     */
    public function getByUser(string $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Payment::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Récupérer les paiements entre deux dates
     */
    public function getByDateRange(string $startDate, string $endDate, int $perPage = 15): LengthAwarePaginator
    {
        return Payment::whereBetween('created_at', [$startDate, $endDate])
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Créer un nouveau paiement
     */
    public function create(array $data): Payment
    {
        return Payment::create($data);
    }

    /**
     * Mettre à jour un paiement
     */
    public function update(string $id, array $data): bool
    {
        $payment = $this->findById($id);

        if (!$payment) {
            return false;
        }

        return $payment->update($data);
    }

    /**
     * Mettre à jour le statut d'un paiement
     */
    public function updateStatus(string $id, string $status): bool
    {
        return $this->update($id, ['status' => $status]);
    }

    /**
     * Mettre à jour la référence externe d'un paiement
     */
    public function updateExternalReference(string $id, string $externalReference): bool
    {
        return $this->update($id, ['external_reference' => $externalReference]);
    }

    /**
     * Supprimer un paiement (soft delete)
     */
    public function delete(string $id): bool
    {
        $payment = $this->findById($id);

        if (!$payment) {
            return false;
        }

        return $payment->delete();
    }

    /**
     * Restaurer un paiement supprimé
     */
    public function restore(string $id): bool
    {
        $payment = Payment::withTrashed()->find($id);

        if (!$payment) {
            return false;
        }

        return $payment->restore();
    }

    /**
     * Supprimer définitivement un paiement
     */
    public function forceDelete(string $id): bool
    {
        $payment = Payment::withTrashed()->find($id);

        if (!$payment) {
            return false;
        }

        return $payment->forceDelete();
    }

    /**
     * Récupérer les statistiques des paiements
     */
    public function getStats(array $filters = []): array
    {
        $query = Payment::query();

        // Appliquer les filtres
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        $stats = $query->selectRaw('
            COUNT(*) as total_count,
            SUM(CASE WHEN status = "SUCCESS" THEN 1 ELSE 0 END) as success_count,
            SUM(CASE WHEN status = "PENDING" THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = "FAILED" THEN 1 ELSE 0 END) as failed_count,
            SUM(CASE WHEN status = "SUCCESS" THEN amount ELSE 0 END) as total_amount,
            AVG(CASE WHEN status = "SUCCESS" THEN amount ELSE NULL END) as average_amount
        ')->first();

        return [
            'total_count' => (int) ($stats->total_count ?? 0),
            'success_count' => (int) ($stats->success_count ?? 0),
            'pending_count' => (int) ($stats->pending_count ?? 0),
            'failed_count' => (int) ($stats->failed_count ?? 0),
            'total_amount' => (float) ($stats->total_amount ?? 0),
            'average_amount' => (float) ($stats->average_amount ?? 0),
            'success_rate' => $stats->total_count ?
                round(($stats->success_count / $stats->total_count) * 100, 2) : 0,
        ];
    }

    /**
     * Récupérer l'historique des statuts d'un paiement
     */
    public function getStatusHistory(string $paymentId): array
    {
        $payment = $this->findById($paymentId);

        if (!$payment) {
            return [];
        }

        $history = [];

        // Statut initial
        $history[] = [
            'status' => $payment->status,
            'timestamp' => $payment->created_at->toISOString(),
            'source' => 'CREATION',
        ];

        // Vérifier les mises à jour dans raw_response
        $rawResponse = $payment->raw_response;
        if (is_array($rawResponse)) {
            if (isset($rawResponse['status_check'])) {
                $history[] = [
                    'status' => $rawResponse['status_check']['status'] ?? null,
                    'timestamp' => $rawResponse['status_check']['last_checked_at'] ?? now()->toISOString(),
                    'source' => 'STATUS_CHECK',
                    'data' => $rawResponse['status_check'],
                ];
            }
        }

        return $history;
    }

    /**
     * Rechercher des paiements avec filtres complets
     */
    public function search(string $query, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $searchQuery = Payment::with(['user'])
            ->where(function ($q) use ($query) {
                $q->where('merchant_payment_reference', 'LIKE', "%{$query}%")
                    ->orWhere('external_reference', 'LIKE', "%{$query}%")
                    ->orWhere('payer_identifier', 'LIKE', "%{$query}%")
                    ->orWhere('description', 'LIKE', "%{$query}%")
                    ->orWhere('compteur_id', 'LIKE', "%{$query}%")
                    ->orWhere('phone', 'LIKE', "%{$query}%")
                    ->orWhere('transaction_id', 'LIKE', "%{$query}%")
                    ->orWhere('dml_reference', 'LIKE', "%{$query}%")
                    ->orWhere('service_type', 'LIKE', "%{$query}%")
                    ->orWhere('payment_type', 'LIKE', "%{$query}%")
                    ->orWhereHas('user', function ($userQuery) use ($query) {
                        $userQuery->where('name', 'LIKE', "%{$query}%")
                            ->orWhere('email', 'LIKE', "%{$query}%")
                            ->orWhere('phone', 'LIKE', "%{$query}%");
                    });
            });

        // Appliquer tous les filtres disponibles
        $this->applyFilters($searchQuery, $filters);

        return $searchQuery->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Applique les filtres à la requête
     */
    private function applyFilters($query, array $filters): void
    {
        // Filtre par statut
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filtre par méthode de paiement
        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        // Filtre par utilisateur (IMPORTANT pour la sécurité)
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        // Filtre par type de service
        if (!empty($filters['service_type'])) {
            $query->where('service_type', $filters['service_type']);
        }

        // Filtre par date de début
        if (!empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        // Filtre par date de fin
        if (!empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        // Filtre par plage de montant (optionnel)
        if (!empty($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }

        if (!empty($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }
    }


    /**
     * Récupérer les paiements échoués nécessitant une nouvelle tentative
     */
    public function getFailedPaymentsForRetry(int $hours = 24): Collection
    {
        return Payment::where('status', 'FAILED')
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereNull('external_reference') // Seulement ceux sans référence externe
            ->get();
    }

    /**
     * Compter les paiements par statut
     */
    public function countByStatus(string $status): int
    {
        return Payment::where('status', $status)->count();
    }

    /**
     * Compter les paiements par méthode
     */
    public function countByPaymentMethod(string $paymentMethod): int
    {
        return Payment::where('payment_method', $paymentMethod)->count();
    }

    /**
     * Récupérer le montant total des paiements
     */
    public function getTotalAmount(array $filters = []): float
    {
        $query = Payment::where('status', 'SUCCESS');

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        return (float) $query->sum('amount');
    }

    /**
     * Vérifier si une référence marchand existe
     */
    public function merchantReferenceExists(string $merchantReference): bool
    {
        return Payment::where('merchant_payment_reference', $merchantReference)->exists();
    }
}
