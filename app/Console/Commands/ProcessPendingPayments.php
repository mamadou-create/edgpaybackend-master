<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ProcessDmlTransactionJob;
use App\Interfaces\DjomyServiceInterface;
use Illuminate\Support\Facades\Log;

class ProcessPendingPayments extends Command
{
    protected $signature = 'payments:process {--sync : Traitement synchrone sans utiliser la file d\'attente}';
    protected $description = 'Traite tous les paiements SUCCESS non encore traités';

    private DjomyServiceInterface $djomyService;

    public function __construct(DjomyServiceInterface $djomyService)
    {
        parent::__construct();
        $this->djomyService = $djomyService;
    }

    public function handle(): int
    {
        Log::info('[Payments Scheduler] Début du traitement des paiements SUCCESS.');
        $this->info('[Payments Scheduler] Début du traitement des paiements SUCCESS.');

        if ($this->option('sync')) {
            // Traitement synchrone
            $this->info("Exécution synchrone du job...");
            $job = new ProcessDmlTransactionJob($this->djomyService);
            $job->handle();
        } else {
            // Traitement asynchrone via queue
            $this->info("Dispatch du job dans la queue...");
            ProcessDmlTransactionJob::dispatch($this->djomyService);
            $this->info("Job dispatché avec succès.");
        }

        Log::info('[Payments Scheduler] Fin du traitement des paiements.');
        $this->info('[Payments Scheduler] Fin du traitement des paiements.');

        return Command::SUCCESS;
    }
}
