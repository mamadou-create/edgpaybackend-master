<?php

namespace App\Console\Commands;

use App\Models\Creance;
use App\Models\CreanceTransaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PurgeStaleCreanceSubmissions extends Command
{
    /**
     * Purge stale pending submissions/transactions that no longer have a valid "source"
     * (missing/trashed client/creance, missing proof file).
     */
    protected $signature = 'creances:purge-stale-submissions
        {--days=30 : Only consider transactions older than this many days}
        {--limit=2000 : Max number of transactions to scan}
        {--apply : Actually soft-delete matching transactions (otherwise dry-run)}
        {--include-no-proof : Consider transactions with no preuve_fichier as missing source}
        {--include-missing-file : Consider transactions whose preuve_fichier does not exist on disk as missing source}
        {--include-deleted-relations : Consider transactions whose client/creance are soft-deleted as missing source}
        {--include-missing-relations : Consider transactions whose client/creance are missing as missing source}
    ';

    protected $description = 'Soft-delete stale pending creance submissions with missing source (client/creance/proof)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $apply = (bool) $this->option('apply');

        $includeNoProof = (bool) $this->option('include-no-proof');
        $includeMissingFile = (bool) $this->option('include-missing-file');
        $includeDeletedRelations = (bool) $this->option('include-deleted-relations');
        $includeMissingRelations = (bool) $this->option('include-missing-relations');

        // Sensible defaults if user didn't specify anything.
        if (!$includeNoProof && !$includeMissingFile && !$includeDeletedRelations && !$includeMissingRelations) {
            $includeNoProof = true;
            $includeMissingFile = true;
            $includeDeletedRelations = true;
            $includeMissingRelations = true;
        }

        if ($days < 0) {
            $this->error('--days must be >= 0');
            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        $hasBatchKey = Schema::hasColumn('creance_transactions', 'batch_key');

        $this->info(sprintf(
            'Scanning up to %d pending transactions older than %d days (created_at <= %s). Mode: %s',
            $limit,
            $days,
            $cutoff->toDateTimeString(),
            $apply ? 'APPLY' : 'DRY-RUN'
        ));

        $query = CreanceTransaction::query()
            ->where('statut', 'en_attente')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit($limit);

        // Load relations including trashed so we can detect them.
        $query->with([
            'creance' => fn ($q) => $q->withTrashed(),
            'client' => fn ($q) => $q->withTrashed(),
        ]);

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            $this->info('No candidates found.');
            return self::SUCCESS;
        }

        $disk = Storage::disk('private');

        /** @var array<string,array{ids: array<int,string>, reasons: array<int,string>}> $targets */
        $targets = [];

        foreach ($candidates as $tx) {
            $reasons = [];

            $client = $tx->client;
            $creance = $tx->creance;

            if ($includeMissingRelations) {
                if (!($client instanceof User)) {
                    $reasons[] = 'client_missing';
                }
                if (!($creance instanceof Creance)) {
                    $reasons[] = 'creance_missing';
                }
            }

            if ($includeDeletedRelations) {
                if (($client instanceof User) && !empty($client->deleted_at)) {
                    $reasons[] = 'client_trashed';
                }
                if (($creance instanceof Creance) && !empty($creance->deleted_at)) {
                    $reasons[] = 'creance_trashed';
                }
            }

            if ($includeNoProof) {
                if (empty($tx->preuve_fichier)) {
                    $reasons[] = 'preuve_missing';
                }
            }

            if ($includeMissingFile) {
                if (!empty($tx->preuve_fichier) && !$disk->exists($tx->preuve_fichier)) {
                    $reasons[] = 'preuve_file_not_found';
                }
            }

            if (empty($reasons)) {
                continue;
            }

            $groupKey = 'tx:' . (string) $tx->id;
            if ($hasBatchKey && !empty($tx->batch_key)) {
                $groupKey = 'batch:' . (string) $tx->batch_key;
            }

            if (!isset($targets[$groupKey])) {
                $targets[$groupKey] = [
                    'ids' => [],
                    'reasons' => [],
                ];
            }

            $targets[$groupKey]['ids'][] = (string) $tx->id;
            $targets[$groupKey]['reasons'][] = implode(',', $reasons);
        }

        if (empty($targets)) {
            $this->info('No stale submissions with missing source found.');
            return self::SUCCESS;
        }

        $totalTx = array_sum(array_map(fn ($g) => count($g['ids']), $targets));
        $this->warn(sprintf('Found %d groups (%d transactions) to purge.', count($targets), $totalTx));

        $shown = 0;
        foreach ($targets as $key => $g) {
            $shown++;
            if ($shown > 20) {
                $this->line('... (truncated)');
                break;
            }
            $this->line(sprintf('%s => %d tx (%s)', $key, count($g['ids']), implode(' | ', array_unique($g['reasons']))));
        }

        if (!$apply) {
            $this->info('Dry-run only. Re-run with --apply to soft-delete these transactions.');
            return self::SUCCESS;
        }

        $deletedCount = 0;

        foreach ($targets as $key => $g) {
            $ids = array_values(array_unique($g['ids']));
            if (empty($ids)) {
                continue;
            }

            // Soft-delete (keeps audit/history) so they disappear from "en attente" lists.
            $deleted = CreanceTransaction::query()->whereIn('id', $ids)->delete();
            $deletedCount += (int) $deleted;
        }

        $this->info(sprintf('Done. Soft-deleted %d transactions.', $deletedCount));
        return self::SUCCESS;
    }
}
