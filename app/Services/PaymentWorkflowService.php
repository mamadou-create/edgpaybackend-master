<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use App\Enums\PaymentStatus;
use App\Interfaces\DjomyServiceInterface;
use App\Mail\PaymentFailedMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Exception;

class PaymentWorkflowService
{
    public function __construct(
        private DjomyServiceInterface $djomyService
    ) {}

    /**
     * Traiter tous les paiements en attente
     */
    public function processPendingPayments(): array
    {
        $payments = Payment::where('status', PaymentStatus::PENDING->value)
            ->whereNull('processed_at')
            ->where('processing_attempts', '<', 3)
            ->get();

        $results = [
            'total' => $payments->count(),
            'successful' => 0,
            'failed' => 0,
            'results' => []
        ];

        foreach ($payments as $payment) {
            try {
                $result = $this->processPayment($payment);
                $results['results'][$payment->id] = $result;

                if ($result['success']) {
                    $results['successful']++;
                    Log::info("✅ Paiement {$payment->id} traité avec succès");
                } else {
                    $results['failed']++;
                    Log::warning("❌ Paiement {$payment->id} échoué: " . ($result['error'] ?? 'Unknown error'));
                }
            } catch (\Exception $e) {
                $results['failed']++;
                Log::error("💥 Exception sur paiement {$payment->id}: " . $e->getMessage());
                $results['results'][$payment->id] = [
                    'success' => false,
                    'error' => 'Exception: ' . $e->getMessage()
                ];
            }
        }

        Log::info("📊 Traitement terminé: {$results['successful']}/{$results['total']} succès, {$results['failed']} échecs");
        return $results;
    }

    /**
     * Traiter un paiement individuel - SANS DML
     */
    public function processPayment(Payment $payment): array
    {
        DB::beginTransaction();

        try {
            // 1️⃣ Éviter les doublons
            if ($payment->isProcessed()) {
                return $this->errorResult($payment, 'Déjà traité');
            }

            // 2️⃣ Vérifier statut Djomy
            $djomyCheck = $this->checkDjomyStatus($payment);
            if (!$djomyCheck['success']) {
                $payment->incrementAttempts(); // ✅ Incrément manuel
                DB::commit();
                return $this->errorResult($payment, $djomyCheck['error']);
            }

            // 3️⃣ Validation des données de base
            $serviceType = $this->getServiceType($payment);
            $compteurId = $this->getCompteurId($payment);

            // Validation
            if ($serviceType === 'prepaid' && empty($compteurId)) {
                $payment->incrementAttempts(); // ✅ Incrément manuel
                DB::commit();
                return $this->errorResult($payment, 'Code client manquant pour prépaiement');
            }

            if ($serviceType === 'postpayment' && empty($compteurId)) {
                $payment->incrementAttempts(); // ✅ Incrément manuel
                DB::commit();
                return $this->errorResult($payment, 'Numéro de facture manquant');
            }

            // 4️⃣ Marquer comme traité (sans DML)
            $this->markAsProcessed($payment, $serviceType);
            DB::commit();

            Log::info("✅ Paiement {$payment->id} marqué comme traité (sans DML)", [
                'service_type' => $serviceType,
                'compteur_id' => $compteurId,
                'attempts' => $payment->processing_attempts
            ]);

            return [
                'success' => true,
                'payment_id' => $payment->id,
                'service_type' => $serviceType,
                'compteur_id' => $compteurId,
                'note' => 'Traitement DML désactivé - Paiement validé uniquement chez Djomy'
            ];
        } catch (Exception $e) {
            DB::rollBack();
            $payment->incrementAttempts(); // ✅ Incrément manuel pour les exceptions

            Log::error("💥 Erreur paiement {$payment->id}: " . $e->getMessage());

            // Notification mail d'échec au propriétaire du paiement + super-admins
            try {
                $paymentUser = $payment->user;
                $failedMail = new PaymentFailedMail(
                    $paymentUser->display_name ?? $paymentUser->phone ?? 'utilisateur',
                    (int) $payment->amount,
                    $payment->compteur_id ?? ($payment->metadata['compteurId'] ?? '—'),
                    $e->getMessage(),
                    'Paiement électronique'
                );
                // Envoi à l'utilisateur
                if ($paymentUser && !empty($paymentUser->email)) {
                    Mail::to($paymentUser->email)->send($failedMail);
                }
                // Envoi à tous les super-admins
                $superAdmins = User::whereHas('role', fn($q) => $q->where('is_super_admin', true))
                    ->whereNotNull('email')->get();
                foreach ($superAdmins as $admin) {
                    Mail::to($admin->email)->send($failedMail);
                }
            } catch (\Throwable $mailEx) {
                Log::error('Erreur envoi PaymentFailedMail (workflow) : ' . $mailEx->getMessage());
            }

            return $this->errorResult($payment, $e->getMessage());
        }
    }

    /**
     * Récupérer le compteur_id depuis différentes sources
     */
    private function getCompteurId(Payment $payment): ?string
    {
        return $payment->compteur_id ?? 
               ($payment->metadata['compteurId'] ?? null) ?? 
               ($payment->metadata['compteur_id'] ?? null);
    }

    /**
     * Marquer le paiement comme traité (sans DML)
     */
    private function markAsProcessed(Payment $payment, string $serviceType): void
    {
        // ✅ Incrémenter AVANT de marquer comme traité
        $payment->incrementAttempts();
        
        // Marquer comme traité
        $payment->markAsProcessed('', $serviceType);
        
        // Mettre à jour les métadonnées
        $payment->setDmlMetadata([
            'dml_disabled' => true, 
            'processed_without_dml' => now()->toISOString(),
            'note' => 'Traitement DML désactivé'
        ]);
    }

    /**
     * Vérifier le statut chez Djomy
     */
    private function checkDjomyStatus(Payment $payment): array
    {
        $transactionId = $payment->transaction_id;

        if (!$transactionId) {
            return ['success' => false, 'error' => 'transactionId manquante'];
        }

        $result = $this->djomyService->getPaymentStatus($transactionId);

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['message'] ?? 'Erreur Djomy'];
        }

        // Vérifier que le statut chez Djomy est valide
        $djomyStatus = $result['data']['status'] ?? null;
        $validStatuses = ['SUCCESS', 'COMPLETED', 'CONFIRMED', 'CREATED'];
        
        if (!in_array($djomyStatus, $validStatuses)) {
            return [
                'success' => false, 
                'error' => "Statut Djomy invalide: {$djomyStatus}"
            ];
        }

        return [
            'success' => true,
            'status' => $djomyStatus
        ];
    }

    /**
     * Déterminer le type de service
     */
    private function getServiceType(Payment $payment): string
    {
        if (!empty($payment->service_type)) {
            return $payment->service_type;
        }

        // Essayer de récupérer depuis les metadata
        $metadata = $payment->metadata ?? [];
        if (!empty($metadata['serviceType'])) {
            return $metadata['serviceType'];
        }

        // Détection automatique depuis la description
        if ($payment->description) {
            $lowerDescription = strtolower($payment->description);
            if (str_contains($lowerDescription, 'postpaid') || 
                str_contains($lowerDescription, 'facture') ||
                str_contains($lowerDescription, 'bill')) {
                return 'postpayment';
            }
        }

        // Par défaut prépayé
        return 'prepaid';
    }

    /**
     * Format de résultat d'erreur
     */
    private function errorResult(Payment $payment, string $error): array
    {
        return [
            'success' => false,
            'payment_id' => $payment->id,
            'error' => $error,
            'attempts' => $payment->processing_attempts
        ];
    }
}