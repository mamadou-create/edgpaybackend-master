<?php

namespace App\Http\Controllers\API;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletTransactionResource;
use App\Models\Commission;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FinancialReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        if (!$actor instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié',
            ], 401);
        }

        if (!$this->isPrivilegedUser($actor)) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé à l\'état financier',
            ], 403);
        }

        [$period, $label, $start, $end] = $this->resolvePeriod($request);
        [$previousStart, $previousEnd] = $this->resolvePreviousPeriod($start, $end);

        $transactions = $this->loadTransactionsBetween($start, $end);
        $previousTransactions = $this->loadTransactionsBetween($previousStart, $previousEnd);

        $signedTransactions = $transactions->map(function (WalletTransaction $transaction): array {
            return [
                'transaction' => $transaction,
                'signed_amount' => $this->signedAmount($transaction),
            ];
        });

        $previousSignedTransactions = $previousTransactions->map(function (WalletTransaction $transaction): array {
            return [
                'transaction' => $transaction,
                'signed_amount' => $this->signedAmount($transaction),
            ];
        });

        $totalCredits = (float) $signedTransactions
            ->filter(fn (array $row) => $row['signed_amount'] >= 0)
            ->sum('signed_amount');

        $totalDebits = (float) $signedTransactions
            ->filter(fn (array $row) => $row['signed_amount'] < 0)
            ->sum(fn (array $row) => abs((float) $row['signed_amount']));

        $previousTotalCredits = (float) $previousSignedTransactions
            ->filter(fn (array $row) => $row['signed_amount'] >= 0)
            ->sum('signed_amount');

        $previousTotalDebits = (float) $previousSignedTransactions
            ->filter(fn (array $row) => $row['signed_amount'] < 0)
            ->sum(fn (array $row) => abs((float) $row['signed_amount']));

        $averageTicket = $transactions->isEmpty()
            ? 0.0
            : (float) $transactions->avg(fn (WalletTransaction $transaction) => (float) $transaction->amount);

        $previousAverageTicket = $previousTransactions->isEmpty()
            ? 0.0
            : (float) $previousTransactions->avg(fn (WalletTransaction $transaction) => (float) $transaction->amount);

        $wallets = Wallet::query()->get([
            'id',
            'cash_available',
            'commission_available',
            'commission_balance',
        ]);

        $walletCashTotal = (float) $wallets->sum(fn (Wallet $wallet) => (float) ($wallet->cash_available ?? 0));
        $walletCommissionTotal = (float) $wallets->sum(
            fn (Wallet $wallet) => (float) (($wallet->commission_available ?? $wallet->commission_balance) ?? 0)
        );
        $walletGlobalTotal = $walletCashTotal + $walletCommissionTotal;

        $commissionRate = (float) (Commission::query()
            ->where('key', 'default_commission_rate')
            ->value('value') ?? 0);

        $commissionFlow = (float) $transactions
            ->filter(fn (WalletTransaction $transaction) => str_contains(strtolower((string) $transaction->type), 'commission'))
            ->sum(fn (WalletTransaction $transaction) => (float) $transaction->amount);

        $breakdown = $signedTransactions
            ->groupBy(function (array $row): string {
                $type = trim((string) $row['transaction']->type);

                return $type !== '' ? strtoupper($type) : 'UNKNOWN';
            })
            ->map(function ($items, string $type): array {
                return [
                    'type' => $type,
                    'count' => (int) $items->count(),
                    'volume' => (float) $items->sum(fn (array $row) => (float) $row['transaction']->amount),
                    'net' => (float) $items->sum('signed_amount'),
                ];
            })
            ->sortByDesc('volume')
            ->values();

        $netFlow = $totalCredits - $totalDebits;
        $previousNetFlow = $previousTotalCredits - $previousTotalDebits;
        $transactionsTotal = (int) $transactions->count();
        $previousTransactionsTotal = (int) $previousTransactions->count();
        $activeUsers = (int) $transactions->pluck('user_id')->filter()->unique()->count();
        $previousActiveUsers = (int) $previousTransactions->pluck('user_id')->filter()->unique()->count();
        $balanceGap = $totalCredits - ($totalDebits + $netFlow);

        $failedTransactionsTotal = $transactions
            ->filter(fn (WalletTransaction $transaction) => $this->transactionMatchesKeywords($transaction, [
                'failed', 'failure', 'echec', 'échoué', 'echoue', 'rejected',
            ]))
            ->count();

        $refundedTransactionsTotal = $transactions
            ->filter(fn (WalletTransaction $transaction) => $this->transactionMatchesKeywords($transaction, [
                'refund', 'refunded', 'remboursement', 'rembourse', 'reversed',
            ]))
            ->count();

        $cancelledTransactionsTotal = $transactions
            ->filter(fn (WalletTransaction $transaction) => $this->transactionMatchesKeywords($transaction, [
                'cancel', 'cancelled', 'annule', 'annulé', 'annulation',
            ]))
            ->count();

        $suspiciousTransactionsTotal = $transactions
            ->filter(fn (WalletTransaction $transaction) => $this->isSuspiciousTransaction($transaction, $averageTicket))
            ->count();

        $topServices = $signedTransactions
            ->groupBy(fn (array $row): string => $this->resolveServiceLabel($row['transaction']))
            ->map(fn (Collection $items, string $label): array => [
                'label' => $label,
                'count' => (int) $items->count(),
                'volume' => (float) $items->sum(fn (array $row) => (float) $row['transaction']->amount),
                'net' => (float) $items->sum('signed_amount'),
            ])
            ->sortByDesc('volume')
            ->take(5)
            ->values();

        $dailySeries = $this->buildDailySeries($signedTransactions, $start, $end);
        $commissionMargin = $totalCredits > 0 ? $commissionFlow / $totalCredits : 0.0;
        $liquidityRatio = $totalDebits > 0 ? $walletCashTotal / $totalDebits : ($walletCashTotal > 0 ? 1.0 : 0.0);

        $comparison = [
            'total_credits' => $this->buildComparisonMetric($totalCredits, $previousTotalCredits),
            'total_debits' => $this->buildComparisonMetric($totalDebits, $previousTotalDebits),
            'net_flow' => $this->buildComparisonMetric($netFlow, $previousNetFlow),
            'transactions_total' => $this->buildComparisonMetric($transactionsTotal, $previousTransactionsTotal),
            'active_users' => $this->buildComparisonMetric($activeUsers, $previousActiveUsers),
            'average_ticket' => $this->buildComparisonMetric($averageTicket, $previousAverageTicket),
        ];

        $alerts = $this->buildAlerts(
            netFlow: $netFlow,
            balanceGap: $balanceGap,
            failedTransactionsTotal: $failedTransactionsTotal,
            refundedTransactionsTotal: $refundedTransactionsTotal,
            suspiciousTransactionsTotal: $suspiciousTransactionsTotal,
            transactionsGrowthRate: $comparison['transactions_total']['change_rate'],
            activeUsersGrowthRate: $comparison['active_users']['change_rate'],
            liquidityRatio: $liquidityRatio,
        );

        return response()->json([
            'success' => true,
            'message' => 'État financier récupéré avec succès',
            'data' => [
                'period' => [
                    'key' => $period,
                    'label' => $label,
                    'date_from' => $start->toDateString(),
                    'date_to' => $end->toDateString(),
                    'generated_at' => now()->toISOString(),
                ],
                'summary' => [
                    'users_total' => (int) User::query()->count(),
                    'wallets_total' => (int) $wallets->count(),
                    'transactions_total' => $transactionsTotal,
                    'active_users' => $activeUsers,
                    'total_credits' => $totalCredits,
                    'total_debits' => $totalDebits,
                    'net_flow' => $netFlow,
                    'average_ticket' => $averageTicket,
                    'wallet_cash_total' => $walletCashTotal,
                    'wallet_commission_total' => $walletCommissionTotal,
                    'wallet_global_total' => $walletGlobalTotal,
                    'commission_flow' => $commissionFlow,
                    'commission_rate' => $commissionRate,
                    'produits' => $totalCredits,
                    'charges' => $totalDebits,
                    'resultat_net' => $netFlow,
                    'actif_total' => $totalCredits,
                    'passif_total' => $totalDebits,
                    'capitaux_propres' => $netFlow,
                    'balance_gap' => $balanceGap,
                ],
                'comparison' => $comparison,
                'breakdown' => $breakdown,
                'daily_series' => $dailySeries,
                'alerts' => $alerts,
                'top_services' => $topServices,
                'risk' => [
                    'failed_transactions_total' => $failedTransactionsTotal,
                    'refunded_transactions_total' => $refundedTransactionsTotal,
                    'cancelled_transactions_total' => $cancelledTransactionsTotal,
                    'suspicious_transactions_total' => $suspiciousTransactionsTotal,
                    'reconciliation_gap' => $balanceGap,
                    'liquidity_ratio' => $liquidityRatio,
                    'commission_margin' => $commissionMargin,
                ],
                'transactions' => WalletTransactionResource::collection($transactions)->resolve(),
            ],
        ]);
    }

    private function loadTransactionsBetween(Carbon $start, Carbon $end): Collection
    {
        return WalletTransaction::query()
            ->with([
                'user:id,display_name,phone,email',
                'wallet:id,user_id,cash_available,commission_available,commission_balance',
            ])
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get();
    }

    private function isPrivilegedUser(User $user): bool
    {
        $roleSlug = $user->role?->slug;

        return $user->role?->is_super_admin === true
            || ($roleSlug !== null && !in_array($roleSlug, [RoleEnum::CLIENT, RoleEnum::PRO, RoleEnum::API_CLIENT], true));
    }

    private function resolvePeriod(Request $request): array
    {
        $period = (string) $request->query('period', 'month');
        $now = now();

        if ($period === 'custom') {
            $dateFrom = (string) $request->query('date_from', $now->toDateString());
            $dateTo = (string) $request->query('date_to', $now->toDateString());

            $start = Carbon::parse($dateFrom)->startOfDay();
            $end = Carbon::parse($dateTo)->endOfDay();

            return [
                'custom',
                $start->format('d/m/Y') . ' → ' . $end->format('d/m/Y'),
                $start,
                $end,
            ];
        }

        return match ($period) {
            'today' => ['today', 'Aujourd\'hui', $now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => ['week', '7 derniers jours', $now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            'year' => ['year', 'Année en cours', $now->copy()->startOfYear(), $now->copy()->endOfDay()],
            default => ['month', 'Mois en cours', $now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };
    }

    private function resolvePreviousPeriod(Carbon $start, Carbon $end): array
    {
        $spanInSeconds = max(1, $end->diffInSeconds($start) + 1);
        $previousEnd = $start->copy()->subSecond();
        $previousStart = $previousEnd->copy()->subSeconds($spanInSeconds - 1);

        return [$previousStart, $previousEnd];
    }

    private function signedAmount(WalletTransaction $transaction): float
    {
        $type = strtolower((string) $transaction->type);
        $debitLike = str_contains($type, 'debit')
            || str_contains($type, 'withdraw')
            || str_contains($type, 'transfer_out')
            || str_contains($type, 'commission_paid')
            || str_contains($type, 'retrait')
            || str_contains($type, 'paiement')
            || str_contains($type, 'payment');

        return $debitLike ? -(float) $transaction->amount : (float) $transaction->amount;
    }

    private function buildComparisonMetric(float|int $current, float|int $previous): array
    {
        $currentValue = (float) $current;
        $previousValue = (float) $previous;
        $delta = $currentValue - $previousValue;

        if (abs($previousValue) < 0.0001) {
            $changeRate = $currentValue == 0.0 ? 0.0 : 100.0;
        } else {
            $changeRate = ($delta / $previousValue) * 100;
        }

        return [
            'current' => $currentValue,
            'previous' => $previousValue,
            'delta' => $delta,
            'change_rate' => round($changeRate, 2),
        ];
    }

    private function buildDailySeries(Collection $signedTransactions, Carbon $start, Carbon $end): array
    {
        $grouped = $signedTransactions->groupBy(fn (array $row): string => Carbon::parse($row['transaction']->created_at)->toDateString());

        return collect(CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()))
            ->map(function (Carbon $date) use ($grouped): array {
                $key = $date->toDateString();
                /** @var Collection<int, array{transaction: WalletTransaction, signed_amount: float}> $items */
                $items = $grouped->get($key, collect());

                $credits = (float) $items
                    ->filter(fn (array $row) => $row['signed_amount'] >= 0)
                    ->sum('signed_amount');
                $debits = (float) $items
                    ->filter(fn (array $row) => $row['signed_amount'] < 0)
                    ->sum(fn (array $row) => abs((float) $row['signed_amount']));

                return [
                    'date' => $key,
                    'label' => $date->format('d/m'),
                    'credits' => $credits,
                    'debits' => $debits,
                    'net' => $credits - $debits,
                    'count' => (int) $items->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function buildAlerts(
        float $netFlow,
        float $balanceGap,
        int $failedTransactionsTotal,
        int $refundedTransactionsTotal,
        int $suspiciousTransactionsTotal,
        float $transactionsGrowthRate,
        float $activeUsersGrowthRate,
        float $liquidityRatio,
    ): array {
        $alerts = [];

        if ($netFlow < 0) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => 'Flux net négatif',
                'message' => 'Les sorties dépassent les entrées sur la période.',
                'metric' => 'net_flow',
                'value' => $netFlow,
            ];
        }

        if (abs($balanceGap) > 1) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => 'Écart d\'équilibrage détecté',
                'message' => 'Le bilan simplifié présente un écart à surveiller.',
                'metric' => 'balance_gap',
                'value' => $balanceGap,
            ];
        }

        if ($failedTransactionsTotal > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => 'Transactions en échec',
                'message' => 'Des transactions ont échoué pendant la période.',
                'metric' => 'failed_transactions_total',
                'value' => $failedTransactionsTotal,
            ];
        }

        if ($refundedTransactionsTotal > 0) {
            $alerts[] = [
                'severity' => 'info',
                'title' => 'Remboursements détectés',
                'message' => 'Des remboursements ont été enregistrés sur la période.',
                'metric' => 'refunded_transactions_total',
                'value' => $refundedTransactionsTotal,
            ];
        }

        if ($suspiciousTransactionsTotal > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => 'Transactions atypiques',
                'message' => 'Des opérations dépassent les seuils usuels de contrôle.',
                'metric' => 'suspicious_transactions_total',
                'value' => $suspiciousTransactionsTotal,
            ];
        }

        if ($transactionsGrowthRate < -15) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => 'Baisse d\'activité',
                'message' => 'Le volume de transactions recule fortement par rapport à la période précédente.',
                'metric' => 'transactions_growth_rate',
                'value' => $transactionsGrowthRate,
            ];
        }

        if ($activeUsersGrowthRate < -10) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => 'Utilisateurs actifs en baisse',
                'message' => 'Le nombre d\'utilisateurs actifs recule par rapport à la période précédente.',
                'metric' => 'active_users_growth_rate',
                'value' => $activeUsersGrowthRate,
            ];
        }

        if ($liquidityRatio < 1) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => 'Liquidité fragile',
                'message' => 'Le cash wallet couvre insuffisamment les sorties observées.',
                'metric' => 'liquidity_ratio',
                'value' => $liquidityRatio,
            ];
        }

        if (empty($alerts)) {
            $alerts[] = [
                'severity' => 'success',
                'title' => 'Aucune alerte majeure',
                'message' => 'Les indicateurs principaux sont stables sur la période.',
                'metric' => 'health',
                'value' => 0,
            ];
        }

        return $alerts;
    }

    private function transactionMatchesKeywords(WalletTransaction $transaction, array $keywords): bool
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $status = strtolower((string) ($metadata['status'] ?? ''));
        $haystack = strtolower(implode(' ', array_filter([
            (string) $transaction->type,
            (string) $transaction->reference,
            (string) $transaction->description,
            $status,
            json_encode($metadata),
        ])));

        foreach ($keywords as $keyword) {
            if (str_contains($haystack, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function isSuspiciousTransaction(WalletTransaction $transaction, float $averageTicket): bool
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $suspiciousFlag = filter_var($metadata['suspicious'] ?? false, FILTER_VALIDATE_BOOL);
        $amount = abs((float) $transaction->amount);
        $largeAmount = $averageTicket > 0 ? $amount >= ($averageTicket * 5) : $amount >= 1000000;

        return $suspiciousFlag
            || $largeAmount
            || $this->transactionMatchesKeywords($transaction, ['fraud', 'chargeback', 'litige']);
    }

    private function resolveServiceLabel(WalletTransaction $transaction): string
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $type = strtolower(trim((string) $transaction->type));

        foreach (['service', 'service_type', 'provider', 'category', 'module', 'product'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return strtoupper(trim($value));
            }
        }

        $searchable = strtolower(implode(' ', array_filter([
            $type,
            (string) $transaction->reference,
            (string) $transaction->description,
            json_encode($metadata),
        ])));

        return match (true) {
            $type === 'wallet_bill_payment' => 'EDG_PREPAID',
            $type === 'debit_wallet_creance' => 'EDG_POSTPAID',
            $type === 'transfer_out' || $type === 'transfer_in' => 'TRANSFERT',
            $type === 'topup' => 'DEPOT',
            $type === 'commission_received' || $type === 'commission_paid' || $type === 'commission_transfer' => 'COMMISSION',
            $type === 'withdrawal_cancelled' => 'RETRAIT_ANNULE',
            str_contains($searchable, 'edg') => 'EDG',
            str_contains($searchable, 'troc') => 'TROC',
            str_contains($searchable, 'commission') => 'COMMISSION',
            str_contains($searchable, 'transfer') || str_contains($searchable, 'transfert') => 'TRANSFERT',
            str_contains($searchable, 'withdraw') || str_contains($searchable, 'retrait') => 'RETRAIT',
            str_contains($searchable, 'deposit') || str_contains($searchable, 'recharge') => 'DEPOT',
            str_contains($searchable, 'payment') || str_contains($searchable, 'paiement') => 'PAIEMENT',
            default => strtoupper(trim((string) $transaction->type)) !== '' ? strtoupper(trim((string) $transaction->type)) : 'AUTRE',
        };
    }
}