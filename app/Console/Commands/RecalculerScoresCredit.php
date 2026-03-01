<?php

namespace App\Console\Commands;

use App\Models\CreditProfile;
use App\Models\User;
use App\Services\RiskScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Commande de recalcul batch des scores de crédit.
 *
 * Usage :
 *   php artisan credit:recalcul-scores            -- tous les profils
 *   php artisan credit:recalcul-scores --risque=eleve -- filtre par niveau
 *   php artisan credit:recalcul-scores --user=uuid   -- profil spécifique
 *
 * Planifier dans routes/console.php :
 *   Schedule::command('credit:recalcul-scores')->daily();
 */
class RecalculerScoresCredit extends Command
{
    protected $signature = 'credit:recalcul-scores
                            {--risque= : Filtre par niveau de risque (faible, moyen, eleve)}
                            {--user= : UUID d\'un utilisateur spécifique}
                            {--limit=500 : Nombre maximum de profils à traiter}';

    protected $description = 'Recalcule les scores de crédit de tous les clients PRO actifs.';

    public function __construct(
        private readonly RiskScoringService $scoring,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('[EDGPAY Credit] Démarrage recalcul scores — ' . now()->toDateTimeString());

        $query = CreditProfile::with('user')
            ->when($this->option('risque'), fn($q) => $q->where('niveau_risque', $this->option('risque')))
            ->when($this->option('user'), fn($q) => $q->where('user_id', $this->option('user')))
            ->limit((int) $this->option('limit'));

        $profils = $query->get();
        $this->info("Profils à traiter : {$profils->count()}");

        $bar      = $this->output->createProgressBar($profils->count());
        $succes   = 0;
        $echecs   = 0;

        foreach ($profils as $profil) {
            try {
                $this->scoring->recalculerScore(
                    $profil->user,
                    'recalcul_batch_quotidien'
                );
                $succes++;
            } catch (\Throwable $e) {
                $this->warn("Erreur user {$profil->user_id}: {$e->getMessage()}");
                $echecs++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✅ Succès : {$succes} | ❌ Échecs : {$echecs}");

        return self::SUCCESS;
    }
}
