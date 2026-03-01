<?php

namespace App\Services;

use App\Enums\RoleEnum;
use App\Interfaces\DmlRepositoryInterface;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class DmlService
{
    private $dmlRepository;
    private $walletService;
    public ?User $user;

    public function __construct(DmlRepositoryInterface $dmlRepository, WalletService $walletService)
    {
        $this->dmlRepository = $dmlRepository;
        $this->walletService = $walletService;
        $this->user = Auth::guard()->user();
    }

    /**
     * Authentification DML
     */
    public function authenticate(array $credentials): array
    {
        Log::info('🔐 DML Service - Authentification attempt', [
            'telephone' => $credentials['telephone'],
            'user_id' => $this->user?->id
        ]);

        return $this->dmlRepository->login(
            $credentials['telephone'],
            $credentials['password']
        );
    }

    /**
     * Rechercher un client prépayé
     */
    public function searchPrepaidCustomer(array $data): array
    {
        try {
            Log::info('🔍 DML Service - Search Prepaid Customer', [
                'rst_value' => $data['rst_value'],
                'user_id' => $this->user->id,
                'user_role' => $this->user->role->slug
            ]);

            $result = $this->dmlRepository->searchPrepaidCustomer($data['rst_value']);

            Log::info('✅ DML Service - Search Prepaid Customer Result', [
                'success' => $result['success'] ?? false,
                'has_data' => !empty($result['data']),
                'rst_value' => $data['rst_value']
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('❌ DML Service - Search Prepaid Customer Error', [
                'error' => $e->getMessage(),
                'rst_value' => $data['rst_value'],
                'user_id' => $this->user->id
            ]);
            return $this->formatServiceError($e->getMessage());
        }
    }

    /**
     * Traiter une transaction prépayée - VERSION SIMPLIFIÉE
     */
    public function processPrepaidTransaction(array $data): array
    {
        try {

            $result = $this->dmlRepository->savePrepaidTransaction($data);

            return $result;
        } catch (\Exception $e) {
            Log::error('DML Service - Process Prepaid Transaction Error: ' . $e->getMessage());
            return $this->formatServiceError($e->getMessage());
        }
    }

    /**
     * Rechercher un client postpayé
     */
    public function searchPostPaymentCustomer(array $data): array
    {
        try {
            Log::info('🔍 DML Service - Search PostPayment Customer', [
                'rst_code' => $data['rst_code'],
                'user_id' => $this->user->id,
                'user_role' => $this->user->role->slug
            ]);

            $result = $this->dmlRepository->searchPostPaymentCustomer($data['rst_code']);

            Log::info('✅ DML Service - Search PostPayment Customer Result', [
                'success' => $result['success'] ?? false,
                'has_data' => !empty($result['data']),
                'rst_code' => $data['rst_code']
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('❌ DML Service - Search PostPayment Customer Error', [
                'error' => $e->getMessage(),
                'rst_code' => $data['rst_code'],
                'user_id' => $this->user->id
            ]);
            return $this->formatServiceError($e->getMessage());
        }
    }

    /**
     * Traiter une transaction postpayée - VERSION SIMPLIFIÉE
     */
    public function processPostPaymentTransaction(array $data): array
    {

        try {

            $result = $this->dmlRepository->savePostPaymentTransaction($data);

            return $result;
        } catch (\Exception $e) {
            Log::error('DML Service - Process PostPayment Transaction Error: ' . $e->getMessage());
            return $this->formatServiceError($e->getMessage());
        }
    }


    /**
     * Extraction du montant pour prépayé
     */
    private function extractAmountForPrepaid(array $data): int
    {
        if (!isset($data['amt'])) {
            throw new \Exception("Champ 'amt' manquant pour la transaction prépayée");
        }

        $value = $data['amt'];
        Log::debug('🔍 DML Service - Extracting prepaid amount', [
            'raw_value' => $value,
            'type' => gettype($value)
        ]);

        return $this->convertToInteger($value, 'amt');
    }

    /**
     * Extraction du montant pour postpayé
     */
    private function extractAmountForPostpayment(array $data): int
    {
        if (!isset($data['montant'])) {
            throw new \Exception("Champ 'montant' manquant pour la transaction postpayée");
        }

        $value = $data['montant'];
        Log::debug('🔍 DML Service - Extracting postpayment amount', [
            'raw_value' => $value,
            'type' => gettype($value)
        ]);

        return $this->convertToInteger($value, 'montant');
    }

    /**
     * Conversion sécurisée en entier
     */
    private function convertToInteger($value, string $fieldName): int
    {
        // Déjà un entier
        if (is_int($value)) {
            return $value;
        }

        // Chaîne de caractères
        if (is_string($value)) {
            $cleanValue = preg_replace('/[^\d]/', '', $value);

            if (empty($cleanValue)) {
                throw new \Exception("La valeur du champ '{$fieldName}' ne contient pas de chiffres valides: '{$value}'");
            }

            $converted = (int) $cleanValue;

            if ($converted <= 0) {
                throw new \Exception("Le montant converti est invalide: {$converted} (original: '{$value}')");
            }

            return $converted;
        }

        // Float
        if (is_float($value)) {
            return (int) round($value);
        }

        throw new \Exception("Type de données non supporté pour le champ '{$fieldName}': " . gettype($value));
    }

    /**
     * Nettoyage des données prépayées
     */
    private function cleanPrepaidData(array $data, int $amount): array
    {
        $cleanData = $data;

        // Utiliser uniquement 'amt' pour prépayé
        $cleanData['amt'] = (string) $amount;

        // Supprimer 'montant' pour éviter la double conversion
        unset($cleanData['montant']);

        return $cleanData;
    }

    /**
     * Nettoyage des données postpayées
     */
    private function cleanPostpaymentData(array $data, int $amount): array
    {
        $cleanData = $data;

        // Utiliser uniquement 'montant' pour postpayé
        $cleanData['montant'] = $amount;

        // Supprimer 'amt' pour éviter la double conversion
        unset($cleanData['amt']);

        return $cleanData;
    }

    /**
     * Validation du solde
     */
    private function validateTransactionBalance(int $montant, string $provider): array
    {
        Log::info('💳 DML Service - Validating transaction balance', [
            'amount' => $montant,
            'provider' => $provider,
            'user_id' => $this->user->id,
            'user_role' => $this->user->role->slug
        ]);

        if ($this->user->role->slug === RoleEnum::CLIENT->value) {
            $result = $this->validateSuperAdminBalance($montant, $provider);
        } else {
            $result = $this->validateUserBalance($montant, $provider);
        }

        Log::info('✅ DML Service - Balance validation completed', $result);
        return $result;
    }

    /**
     * Validation du solde utilisateur (PROS)
     */
    private function validateUserBalance(int $amount, string $provider): array
    {
        $userId = $this->user->id;

        Log::debug('👤 DML Service - Validating user balance', [
            'user_id' => $userId,
            'amount' => $amount,
            'provider' => $provider
        ]);

        $wallet = $this->walletService->getWalletByUserId($userId);

        if (!$wallet) {
            throw new \Exception("Wallet utilisateur introuvable pour #{$userId}");
        }

        $user = $wallet->user;

        // Vérification du solde disponible du wallet
        $soldeDisponible = $wallet->cash_available - $wallet->blocked_amount;
        if ($soldeDisponible < $amount) {
            throw new \Exception("Solde disponible du wallet insuffisant. Disponible: {$soldeDisponible}, Montant: {$amount}");
        }

        // Vérification du solde utilisateur
        if ($user->solde_portefeuille < $amount) {
            throw new \Exception("Solde utilisateur insuffisant ({$user->solde_portefeuille}) pour un montant de {$amount}.");
        }

        // Vérification du solde dans le float
        $float = $wallet->floats()->where('provider', $provider)->first();
        if (!$float) {
            throw new \Exception("Flotte introuvable pour le provider {$provider}");
        }

        if ($float->balance < $amount) {
            throw new \Exception("Solde du float insuffisant ({$float->balance}) pour un montant de {$amount}.");
        }

        return [
            'wallet_type' => 'user',
            'wallet_id' => $wallet->id,
            'user_id' => $userId,
            'balances' => [
                'wallet_available' => $soldeDisponible,
                'user_balance' => $user->solde_portefeuille,
                'float_balance' => $float->balance,
                'required_amount' => $amount,
            ],
            'sufficient' => true
        ];
    }

    /**
     * Validation du solde super admin (pour CLIENTS)
     */
    private function validateSuperAdminBalance(int $amount, string $provider): array
    {
        Log::debug('👑 DML Service - Validating super admin balance', [
            'client_id' => $this->user->id,
            'amount' => $amount,
            'provider' => $provider
        ]);

        $superAdmin = User::whereHas('role', function ($query) {
            $query->where('slug', 'super_admin');
        })->first();

        if (!$superAdmin) {
            throw new \Exception('Super administrateur non trouvé');
        }

        $superWallet = $this->walletService->getWalletByUserId($superAdmin->id);

        if (!$superWallet) {
            throw new \Exception("Wallet du super administrateur introuvable");
        }

        // Vérification du solde disponible du super admin
        $superAdminSoldeDisponible = $superWallet->cash_available - $superWallet->blocked_amount;
        if ($superAdminSoldeDisponible < $amount) {
            throw new \Exception("Solde disponible du super admin insuffisant. Disponible: {$superAdminSoldeDisponible}, Montant requis: {$amount}");
        }

        // Vérification du solde utilisateur du super admin
        if ($superAdmin->solde_portefeuille < $amount) {
            throw new \Exception("Solde utilisateur du super admin insuffisant ({$superAdmin->solde_portefeuille}) pour un montant de {$amount}.");
        }

        // Vérification du solde dans le float du super admin
        $superFloat = $superWallet->floats()->where('provider', $provider)->first();
        if (!$superFloat) {
            throw new \Exception("Flotte introuvable pour le provider {$provider} dans le wallet du super admin");
        }

        if ($superFloat->balance < $amount) {
            throw new \Exception("Solde du float du super admin insuffisant ({$superFloat->balance}) pour un montant de {$amount}.");
        }

        return [
            'wallet_type' => 'super_admin',
            'wallet_id' => $superWallet->id,
            'super_admin_id' => $superAdmin->id,
            'client_id' => $this->user->id,
            'balances' => [
                'wallet_available' => $superAdminSoldeDisponible,
                'user_balance' => $superAdmin->solde_portefeuille,
                'float_balance' => $superFloat->balance,
                'required_amount' => $amount,
            ],
            'sufficient' => true
        ];
    }

    /**
     * Autres méthodes (simplifiées)
     */
    public function checkTransactionStatus(array $data): array
    {
        Log::info('📊 DML Service - Checking transaction status', [
            'ref_facture' => $data['ref_facture'],
            'user_id' => $this->user->id
        ]);

        try {
            return $this->dmlRepository->getTransaction($data['ref_facture']);
        } catch (\Exception $e) {
            Log::error('❌ DML Service - Check Transaction Status Error', [
                'error' => $e->getMessage(),
                'ref_facture' => $data['ref_facture']
            ]);
            return $this->formatServiceError($e->getMessage());
        }
    }

    public function getAccountBalance(): array
    {
        Log::info('🏦 DML Service - Getting account balance', [
            'user_id' => $this->user->id
        ]);

        try {
            return $this->dmlRepository->getBalance();
        } catch (\Throwable $e) {
            Log::error('❌ DML Service - Get Account Balance Error', [
                'error' => $e->getMessage()
            ]);
            return $this->formatServiceError($e->getMessage());
        }
    }

    /**
     * Méthodes utilitaires
     */
    private function getDataTypes(array $data): array
    {
        $types = [];
        foreach ($data as $key => $value) {
            $types[$key] = gettype($value);
        }
        return $types;
    }

    public function syncTransactions(array $dateRange = []): array
    {
        try {
            $startDate = $dateRange['start_date'] ?? now()->subDays(7)->format('Y-m-d');
            $endDate = $dateRange['end_date'] ?? now()->format('Y-m-d');

            Log::info("DML Sync Transactions from {$startDate} to {$endDate}");

            return [
                'success' => true,
                'message' => 'Synchronisation démarrée',
                'data' => [
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate
                    ]
                ]
            ];
        } catch (\Exception $e) {
            Log::error('DML Service - Sync Transactions Error: ' . $e->getMessage());
            return $this->formatServiceError($e->getMessage());
        }
    }

    public function generateActivityReport(array $criteria): array
    {
        try {
            $userId = $criteria['user_id'] ?? null;
            $startDate = $criteria['start_date'];
            $endDate = $criteria['end_date'];

            return [
                'success' => true,
                'message' => 'Rapport généré avec succès',
                'data' => [
                    'report' => [
                        'period' => "{$startDate} to {$endDate}",
                        'user_id' => $userId,
                        'generated_at' => now()->toDateTimeString(),
                        'summary' => [
                            'total_transactions' => 0,
                            'total_amount' => 0,
                            'success_rate' => '100%'
                        ]
                    ]
                ]
            ];
        } catch (\Exception $e) {
            Log::error('DML Service - Generate Activity Report Error: ' . $e->getMessage());
            return $this->formatServiceError($e->getMessage());
        }
    }

    private function formatServiceError(string $message): array
    {
        return [
            'success' => false,
            'error' => 'Erreur de service: ' . $message,
            'status' => 500
        ];
    }
}
