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

        $transactions = WalletTransaction::query()
            ->with([
                'user:id,display_name,phone,email',
                'wallet:id,user_id,cash_available,commission_available,commission_balance',
            ])
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get();

        $signedTransactions = $transactions->map(function (WalletTransaction $transaction): array {
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

        $averageTicket = $transactions->isEmpty()
            ? 0.0
            : (float) $transactions->avg(fn (WalletTransaction $transaction) => (float) $transaction->amount);

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
                    'transactions_total' => (int) $transactions->count(),
                    'active_users' => (int) $transactions->pluck('user_id')->filter()->unique()->count(),
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
                    'balance_gap' => $totalCredits - ($totalDebits + $netFlow),
                ],
                'breakdown' => $breakdown,
                'transactions' => WalletTransactionResource::collection($transactions)->resolve(),
            ],
        ]);
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

    private function signedAmount(WalletTransaction $transaction): float
    {
        $type = strtolower((string) $transaction->type);
        $debitLike = str_contains($type, 'debit')
            || str_contains($type, 'withdraw')
            || str_contains($type, 'retrait')
            || str_contains($type, 'paiement')
            || str_contains($type, 'payment');

        return $debitLike ? -(float) $transaction->amount : (float) $transaction->amount;
    }
}