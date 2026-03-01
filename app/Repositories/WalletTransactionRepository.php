<?php

namespace App\Repositories;

use App\Interfaces\WalletTransactionRepositoryInterface;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class WalletTransactionRepository implements WalletTransactionRepositoryInterface
{

     protected $model;

      public function __construct(WalletTransaction $model)
    {
        $this->model = $model;
    }


    /**
     * Récupérer toutes les transactions
     */
    public function getAll(): iterable
    {
        try {
            return WalletTransaction::with(['user', 'wallet'])->latest()->get();
        } catch (\Exception $e) {
            Log::error("Erreur getAll WalletTransaction : " . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupérer une transaction par son ID
     */
    public function getById(string $id): ?WalletTransaction
    {
        try {
            return WalletTransaction::with(['user', 'wallet'])->find($id);
        } catch (\Exception $e) {
            Log::error("Erreur getById WalletTransaction [id=$id] : " . $e->getMessage());
            return null;
        }
    }

    /**
     * Créer une transaction
     */
    public function create(array $data): ?WalletTransaction
    {
        try {
            return WalletTransaction::create($data);
        } catch (\Exception $e) {
            Log::error("Erreur create WalletTransaction : " . $e->getMessage(), $data);
            return null;
        }
    }

    /**
     * Supprimer une transaction
     */
    public function delete(string $id): bool
    {
        try {
            $transaction = $this->getById($id);
            if (!$transaction) {
                return false;
            }

            return (bool) $transaction->delete();
        } catch (\Exception $e) {
            Log::error("Erreur delete WalletTransaction [id=$id] : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupérer toutes les transactions d’un wallet
     */
    public function findByWallet(string $walletId): iterable
    {
        try {
            return WalletTransaction::with(['user', 'wallet'])->where('wallet_id', $walletId)
                ->latest()
                ->get();
        } catch (\Exception $e) {
            Log::error("Erreur findByWallet [wallet_id=$walletId] : " . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupérer toutes les transactions d’un utilisateur
     */
    public function findByUser(string $userId): iterable
    {
        try {
            return WalletTransaction::with(['user', 'wallet'])->where('user_id', $userId)
                ->latest()
                ->get();
        } catch (\Exception $e) {
            Log::error("Erreur findByUser [user_id=$userId] : " . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupérer les transactions par type
     */
    public function findByType(string $type): iterable
    {
        try {
            return WalletTransaction::with(['user', 'wallet'])->where('type', $type)
                ->latest()
                ->get();
        } catch (\Exception $e) {
            Log::error("Erreur findByType [type=$type] : " . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupérer le query builder
     */
    public function getQuery(): Builder
    {
        return $this->model->newQuery();
    }
}
