<?php

namespace App\Jobs;

use App\Services\PaymentWorkflowService;
use App\Interfaces\DjomyServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDmlTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private PaymentWorkflowService $workflow;

    /**
     * Create a new job instance.
     */
    public function __construct(DjomyServiceInterface $djomyService)
    {
        // Instanciation correcte du service avec DjomyService
        $this->workflow = new PaymentWorkflowService($djomyService);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('[ProcessDmlTransactionJob] Début du traitement des paiements en attente.');

        try {
            $results = $this->workflow->processPendingPayments();

            Log::info('[ProcessDmlTransactionJob] Traitement terminé', [
                'success' => $results['success'],
                'successful' => $results['successful'],
                'failed' => $results['failed'],
                'attempts' => $results['processing_attempts']
            ]);
        } catch (\Exception $e) {
            Log::error('[ProcessDmlTransactionJob] Erreur lors du traitement des paiements : ' . $e->getMessage());
        }
    }
}
