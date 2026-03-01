<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\WalletTransactionRepositoryInterface;
use App\Classes\ApiResponseClass;
use App\Http\Requests\Wallet\WalletTransactionRequest;
use App\Http\Resources\WalletTransactionResource;
use Illuminate\Support\Facades\DB;

class WalletTransactionController extends Controller
{
    private $transactionRepository;

    public function __construct(WalletTransactionRepositoryInterface $transactionRepository)
    {
        $this->transactionRepository = $transactionRepository;
    }

    /**
     * 📥 Liste des transactions
     */
    public function index()
    {
        try {
            $transactions = $this->transactionRepository->getAll();
            return ApiResponseClass::sendResponse(
                WalletTransactionResource::collection($transactions),
                'Transactions récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération des transactions');
        }
    }

    /**
     * 📄 Détail d’une transaction
     */
    public function show($id)
    {
        try {
            $transaction = $this->transactionRepository->getById($id);
            if (!$transaction) {
                return ApiResponseClass::notFound('Transaction introuvable');
            }

            return ApiResponseClass::sendResponse(
                new WalletTransactionResource($transaction),
                'Transaction récupérée avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError('Erreur lors de la récupération de la transaction');
        }
    }

    /**
     * 🆕 Création d’une transaction
     */
    public function store(WalletTransactionRequest $request)
    {
        DB::beginTransaction();
        try {
            $transaction = $this->transactionRepository->create($request->validated());

            DB::commit();
            return ApiResponseClass::created(
                new WalletTransactionResource($transaction),
                'Transaction créée avec succès'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la création de la transaction");
        }
    }

    /**
     * ❌ Suppression
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $success = $this->transactionRepository->delete($id);

            if (!$success) {
                DB::rollBack();
                return ApiResponseClass::notFound('Transaction introuvable');
            }

            DB::commit();
            return ApiResponseClass::sendResponse([], 'Transaction supprimée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return ApiResponseClass::rollback($e, "Erreur lors de la suppression de la transaction");
        }
    }

    /**
     * 🔍 Transactions par wallet
     */
    public function findByWallet($walletId)
    {
        try {
            $transactions = $this->transactionRepository->findByWallet($walletId);
            return ApiResponseClass::sendResponse(
                WalletTransactionResource::collection($transactions),
                'Transactions du wallet récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError("Erreur lors de la récupération des transactions du wallet");
        }
    }

    /**
     * 🔍 Transactions par utilisateur
     */
    public function findByUser($userId)
    {
        try {
            $transactions = $this->transactionRepository->findByUser($userId);
            return ApiResponseClass::sendResponse(
                WalletTransactionResource::collection($transactions),
                'Transactions de l’utilisateur récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError("Erreur lors de la récupération des transactions de l’utilisateur");
        }
    }

    /**
     * 🔍 Transactions par type
     */
    public function findByType($type)
    {
        try {
            $transactions = $this->transactionRepository->findByType($type);
            return ApiResponseClass::sendResponse(
                WalletTransactionResource::collection($transactions),
                'Transactions du type récupérées avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::serverError("Erreur lors de la récupération des transactions par type");
        }
    }
}
