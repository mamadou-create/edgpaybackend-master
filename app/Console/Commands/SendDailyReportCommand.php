<?php

namespace App\Console\Commands;

use App\Mail\DailyReportMail;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendDailyReportCommand extends Command
{
    protected $signature   = 'report:daily';
    protected $description = 'Envoie le rapport journalier des transactions par mail aux super-admins';

    public function handle(): int
    {
        $date  = now()->subDay()->format('d/m/Y');
        $start = now()->subDay()->startOfDay();
        $end   = now()->subDay()->endOfDay();

        // ── Transactions du jour ──────────────────────────────────────────
        $transactions = WalletTransaction::whereBetween('created_at', [$start, $end])
            ->with('user')
            ->get();

        $totalCount  = $transactions->count();
        $totalVolume = $transactions->where('amount', '>', 0)->sum('amount');

        // Ventilation par type
        $byType = $transactions
            ->groupBy('type')
            ->map(fn($group) => [
                'count'  => $group->count(),
                'volume' => $group->where('amount', '>', 0)->sum('amount'),
            ])
            ->toArray();

        // 5 transactions les plus importantes (montant positif)
        $topTransactions = $transactions
            ->where('amount', '>', 0)
            ->sortByDesc('amount')
            ->take(5)
            ->values()
            ->map(fn($t) => [
                'amount'      => $t->amount,
                'type'        => $t->type,
                'description' => $t->description,
                'user'        => $t->user?->display_name ?? $t->user?->phone ?? '—',
                'reference'   => $t->reference,
            ])
            ->toArray();

        // ── Nouveaux utilisateurs ─────────────────────────────────────────
        $newUsers = User::whereBetween('created_at', [$start, $end])->count();

        // ── Demandes de retrait ───────────────────────────────────────────
        $withdrawalStats = [];
        if (class_exists(\App\Models\WithdrawalRequest::class)) {
            $wr = \App\Models\WithdrawalRequest::whereBetween('created_at', [$start, $end])->get();
            $withdrawalStats = [
                'total'    => $wr->count(),
                'pending'  => $wr->where('status', 'PENDING')->count(),
                'approved' => $wr->where('status', 'APPROVED')->count(),
                'rejected' => $wr->where('status', 'REJECTED')->count(),
                'volume'   => $wr->where('status', 'APPROVED')->sum('amount'),
            ];
        }

        // ── Demandes de recharge ──────────────────────────────────────────
        $topupStats = [];
        if (class_exists(\App\Models\TopupRequest::class)) {
            $tr = \App\Models\TopupRequest::whereBetween('created_at', [$start, $end])->get();
            $topupStats = [
                'total'    => $tr->count(),
                'pending'  => $tr->where('status', 'PENDING')->count(),
                'approved' => $tr->where('status', 'APPROVED')->count(),
                'rejected' => $tr->where('status', 'REJECTED')->count(),
                'volume'   => $tr->where('status', 'APPROVED')->sum('amount'),
            ];
        }

        $stats = [
            'total_transactions' => $totalCount,
            'total_volume'       => $totalVolume,
            'by_type'            => $byType,
            'top_transactions'   => $topTransactions,
            'new_users'          => $newUsers,
            'withdrawal'         => $withdrawalStats,
            'topup'              => $topupStats,
        ];

        // ── Destinataires : tous les super-admins qui ont un email ────────
        $recipients = User::whereHas('role', fn($q) => $q->where('is_super_admin', true))
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($recipients)) {
            $this->warn('Aucun super-admin avec email trouvé. Rapport non envoyé.');
            Log::warning('[DailyReport] Aucun destinataire.');
            return Command::FAILURE;
        }

        $sent = 0;
        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new DailyReportMail($stats, $date));
                $sent++;
            } catch (\Throwable $e) {
                Log::error("[DailyReport] Erreur envoi à $email : " . $e->getMessage());
            }
        }

        $this->info("[DailyReport] Rapport du $date envoyé à $sent destinataire(s).");
        Log::info("[DailyReport] Rapport du $date envoyé à $sent destinataire(s).");

        return Command::SUCCESS;
    }
}
