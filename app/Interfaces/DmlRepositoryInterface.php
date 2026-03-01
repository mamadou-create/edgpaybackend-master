<?php

namespace App\Interfaces;

interface DmlRepositoryInterface
{
    /**
     * Rechercher un client prépayé
     */
    public function searchPrepaidCustomer(string $rstValue): array;

    /**
     * Sauvegarder une transaction prépayée
     */
    public function savePrepaidTransaction(array $data): array;

    /**
     * Rechercher un client postpayé
     */
    public function searchPostPaymentCustomer(string $rstCode): array;

    /**
     * Sauvegarder une transaction postpayée
     */
    public function savePostPaymentTransaction(array $data): array;

    /**
     * Obtenir une transaction
     */
    public function getTransaction(string $refFacture): array;

    /**
     * Obtenir le solde
     */
    public function getBalance(): array;

    /**
     * Authentification DML
     */
    public function login(string $telephone, string $password): array;

    /**
     * Enregistrer une transaction en base de données
     */
    public function storeTransaction(array $transactionData): array;

    /**
     * Obtenir l'historique des transactions
     */
    public function getTransactionHistory(string $userId, int $perPage = 20): array;

    /**
     * Trouver une transaction par référence
     */
    public function findTransactionByReference(string $reference): ?array;
}