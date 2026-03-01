<?php

namespace App\Interfaces;

use App\Helpers\HelperStatus;
use App\Models\Wallet;
use App\Models\WalletFloat;

interface WalletRepositoryInterface extends CrudInterface
{
    /**
     * Trouve le portefeuille lié à un utilisateur.
     */
    public function findByUser(string $userId): iterable;

    /**
     * Met à jour le solde disponible (cash).
     */
    public function updateBalance(string $walletId, int $amount): bool;

    /**
     * Ajoute une commission au portefeuille.
     */
    // public function addCommission(string $walletId, int $commission): bool;
    public function addCommission(string $walletId, string $userId, float $amount): bool;

    /**
     * Déduit un montant du portefeuille (retrait).
     */
    public function withdraw(string $walletId, int $amount): bool;

    public function credit(string $walletId, float $amount): bool;

    // 👇 ajoute cette ligne
    public function findForUpdate(string $walletId): ?Wallet;

    public function updateFloatRate(string $id, float $rate): bool;

    public function rechargeSuperAdmin(string $walletId, int $amount, ?string $description = null): bool;

    // Nouvelles méthodes ajoutées
    public function getFloatByWalletAndProvider(string $walletId, string $provider): ?WalletFloat;
    public function getByUserId(string $userId): ?Wallet;
    public function addFloat(string $walletId, string $provider, int $balance, int $commission, float $rate): bool;
    public function removeFloat(string $walletId, string $provider): bool;
    public function updateCashAvailable(string $walletId, int $amount): bool;
    public function updateCommissionAvailable(string $walletId, int $commission): bool;
    public function transferCommissionToBalance(string $walletId, int $amount): bool;
    /**
     * Transférer de l'argent entre deux floats via un wallet
     */
    public function transferWalletBetweenFloats(string $walletId, string $floatFromId, string $floatToId, int $amount, ?string $description = null): bool;
    public function transferBetweenFloatProviders(string $walletId, string $formProvider, string $toProvider, int $amount, ?string $description = null): bool;
    public function getFloatByIdForUpdate(string $floatId): ?WalletFloat;
    public function updateFloatBalance(string $floatId, int $newBalance): bool;

    public function getWithUser(string $id): ?Wallet;
    public function walletExistsForUser(string $userId): bool;
    public function blockAmount(string $walletId, int $amount): bool;
    public function unblockAmount(string $walletId, int $amount): bool;
    public function unblockAndWithdraw(string $walletId, int $amount): bool;

    public function getUserStats($userId);

    public function getConsistentBalance(string $userId): array;
    public function getAvailableBalance(string $userId): int;
    public function hasSufficientBalance(string $userId, int $amount): array;
    public function getTotalCashAvailable(): int;

    /**
     * Récupère le résumé des commissions par rôle (EDG, GSS, SOUS-ADMIN)
     * Exclut automatiquement les SUPER_ADMIN
     * 
     * @param array $roles Les rôles à inclure (par défaut: EDG, GSS, SOUS-ADMIN)
     * @param string|null $currency Devise spécifique (optionnel)
     * @return array
     */
    public function getCommissionSummary(?string $currency = null): array;
}
