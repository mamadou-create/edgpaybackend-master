<?php

namespace App\Console\Commands;

use App\Enums\RoleEnum;
use App\Models\CreditProfile;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Crée les profils crédit manquants pour les anciens comptes PRO.
 *
 * Usage :
 *   php artisan credit:backfill-profils
 *   php artisan credit:backfill-profils --dry-run
 *   php artisan credit:backfill-profils --user=uuid
 */
class BackfillCreditProfiles extends Command
{
    protected $signature = 'credit:backfill-profils
                            {--dry-run : Affiche les comptes concernés sans écrire en base}
                            {--user= : UUID d\'un utilisateur spécifique}
                            {--limit=500 : Nombre maximum de comptes à traiter}';

    protected $description = 'Crée les profils crédit manquants pour les comptes PRO existants.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $query = User::query()
            ->with(['role', 'creditProfile'])
            ->whereDoesntHave('creditProfile')
            ->where(function ($builder) {
                $builder
                    ->where('is_pro', true)
                    ->orWhereHas('role', function ($roleQuery) {
                        $roleQuery->where('slug', RoleEnum::PRO);
                    })
                    ->orWhereHas('creances');
            })
            ->when(
                $this->option('user'),
                fn ($builder) => $builder->where('id', $this->option('user'))
            )
            ->orderBy('created_at')
            ->limit($limit);

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->info('Aucun profil crédit manquant à créer.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[EDGPAY Credit] %d compte(s) sans profil crédit détecté(s)%s.',
            $users->count(),
            $dryRun ? ' (dry-run)' : ''
        ));

        $created = 0;
        $bar = $this->output->createProgressBar($users->count());

        foreach ($users as $user) {
            if ($dryRun) {
                $this->newLine();
                $this->line(sprintf(
                    '- %s | %s | is_pro=%s',
                    $user->id,
                    $user->email ?? $user->phone ?? 'sans identifiant',
                    $user->is_pro ? 'true' : 'false'
                ));
                $bar->advance();
                continue;
            }

            CreditProfile::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'credit_limite' => 0,
                    'credit_disponible' => 0,
                    'score_fiabilite' => 100,
                    'niveau_risque' => 'faible',
                    'total_encours' => 0,
                ]
            );

            $created++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($dryRun) {
            $this->info('Dry-run terminé. Aucun enregistrement n\'a été créé.');
            return self::SUCCESS;
        }

        $this->info("✅ Profils crédit créés : {$created}");

        return self::SUCCESS;
    }
}