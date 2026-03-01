<?php

namespace App\Interfaces;

interface DmlServiceInterface
{
    /**
     * Rechercher un client prépayé avec validation
     */
    public function searchPrepaidCustomer(array $data): array;

    /**
     * Exécuter une transaction prépayée
     */
    public function processPrepaidTransaction(array $data): array;

    /**
     * Rechercher un client postpayé avec validation
     */
    public function searchPostPaymentCustomer(array $data): array;

    /**
     * Exécuter une transaction postpayée
     */
    public function processPostPaymentTransaction(array $data): array;

    /**
     * Vérifier le statut d'une transaction
     */
    public function checkTransactionStatus(array $data): array;

    /**
     * Obtenir le solde du compte
     */
    public function getAccountBalance(): array;

    /**
     * Obtenir l'historique des transactions
     */
    public function getTransactionHistory(array $filters = []): array;

    /**
     * Synchroniser les transactions
     */
    public function syncTransactions(array $dateRange = []): array;

    /**
     * Générer un rapport d'activité
     */
    public function generateActivityReport(array $criteria): array;
}