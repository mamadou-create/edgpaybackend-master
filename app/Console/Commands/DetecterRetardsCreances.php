<?php

namespace App\Console\Commands;

use App\Models\Creance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Commande de détection et mise à jour des créances en retard.
 * À planifier quotidiennement.
 *
 * php artisan credit:detecter-retards
 */
class DetecterRetardsCreances extends Command
{
    protected $signature   = 'credit:detecter-retards';
    protected $description = 'Marque les créances en retard et met à jour les jours de retard.';

    public function handle(): int
    {
        $this->info('[EDGPAY Credit] Détection retards — ' . now()->toDateTimeString());

        $now = now()->toDateString();

        // Mettre à jour les créances en retard
        $nb = DB::table('creances')
            ->where('date_echeance', '<', $now)
            ->whereNotIn('statut', ['payee', 'annulee', 'contentieux'])
            ->whereNull('deleted_at')
            ->update([
                'statut'      => 'en_retard',
                'jours_retard'=> DB::raw("DATEDIFF(NOW(), date_echeance)"),
                'updated_at'  => now(),
            ]);

        $this->info("✅ {$nb} créances marquées en retard.");

        // Recalculer les encours dans les profils
        DB::statement("
            UPDATE credit_profiles cp
            INNER JOIN (
                SELECT user_id, SUM(montant_restant) as total
                FROM creances
                WHERE statut NOT IN ('payee', 'annulee')
                  AND deleted_at IS NULL
                GROUP BY user_id
            ) enc ON cp.user_id = enc.user_id
            SET cp.total_encours = enc.total,
                cp.credit_disponible = GREATEST(0, cp.credit_limite - enc.total),
                cp.updated_at = NOW()
        ");

        $this->info('✅ Encours mis à jour dans les profils de crédit.');

        return self::SUCCESS;
    }
}
