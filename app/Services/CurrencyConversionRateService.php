<?php

namespace App\Services;

use App\Models\CurrencyConversionRate;
use App\Models\CurrencyConversionRateHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CurrencyConversionRateService
{
    public function create(User $actor, array $data): CurrencyConversionRate
    {
        return DB::transaction(function () use ($actor, $data): CurrencyConversionRate {
            $rate = CurrencyConversionRate::create([
                'wallet_currency' => strtoupper($data['to_currency']),
                'payment_currency' => strtoupper($data['from_currency']),
                'rate' => $data['rate'],
                'enabled' => false,
                'status' => CurrencyConversionRate::STATUS_DRAFT,
                'effective_from' => $data['effective_from'] ?? now(),
                'effective_to' => $data['effective_to'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->record($rate, $actor, 'CREATED', null, $rate->fresh()->toArray());
            AuditLogService::log('currency_rate_created', (string) $rate->id, CurrencyConversionRate::class, contexte: ['from_currency' => $rate->payment_currency, 'to_currency' => $rate->wallet_currency], module: 'currency');

            return $rate;
        });
    }

    public function activate(CurrencyConversionRate $rate, User $actor): CurrencyConversionRate
    {
        return DB::transaction(function () use ($rate, $actor): CurrencyConversionRate {
            $rate = CurrencyConversionRate::query()->lockForUpdate()->findOrFail($rate->id);
            if ($rate->status !== CurrencyConversionRate::STATUS_APPROVED || $rate->approved_at === null) {
                throw new \LogicException('Un taux doit être approuvé avant son activation.');
            }
            if ($rate->effective_to !== null && $rate->effective_to->isPast()) {
                throw new \DomainException('Un taux expiré ne peut pas être activé.');
            }

            $others = CurrencyConversionRate::query()
                ->lockForUpdate()
                ->where('payment_currency', $rate->payment_currency)
                ->where('wallet_currency', $rate->wallet_currency)
                ->where('id', '!=', $rate->id)
                ->where('status', CurrencyConversionRate::STATUS_ACTIVE)
                ->get();
            foreach ($others as $other) {
                $before = $other->toArray();
                $other->update(['status' => CurrencyConversionRate::STATUS_INACTIVE, 'enabled' => false]);
                $this->record($other, $actor, 'SUPERSEDED', $before, $other->fresh()->toArray());
            }

            $before = $rate->toArray();
            $rate->update([
                'status' => CurrencyConversionRate::STATUS_ACTIVE,
                'enabled' => true,
                'approved_by' => $actor->id,
            ]);
            $rate = $rate->fresh();
            $this->record($rate, $actor, 'ACTIVATED', $before, $rate->toArray());
            AuditLogService::log('currency_rate_activated', (string) $rate->id, CurrencyConversionRate::class, contexte: ['from_currency' => $rate->payment_currency, 'to_currency' => $rate->wallet_currency], module: 'currency');

            return $rate;
        });
    }

    public function approve(CurrencyConversionRate $rate, User $actor): CurrencyConversionRate
    {
        return DB::transaction(function () use ($rate, $actor): CurrencyConversionRate {
            $rate = CurrencyConversionRate::query()->lockForUpdate()->findOrFail($rate->id);
            if ($rate->status !== CurrencyConversionRate::STATUS_DRAFT) {
                throw new \LogicException('Seul un taux en brouillon peut être approuvé.');
            }
            if ($rate->effective_to !== null && $rate->effective_to->isPast()) {
                throw new \DomainException('Un taux expiré ne peut pas être approuvé.');
            }

            $before = $rate->toArray();
            $rate->update([
                'status' => CurrencyConversionRate::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $rate = $rate->fresh();
            $this->record($rate, $actor, 'APPROVED', $before, $rate->toArray());
            AuditLogService::log('currency_rate_approved', (string) $rate->id, CurrencyConversionRate::class, contexte: ['from_currency' => $rate->payment_currency, 'to_currency' => $rate->wallet_currency], module: 'currency');

            return $rate;
        });
    }

    public function deactivate(CurrencyConversionRate $rate, User $actor): CurrencyConversionRate
    {
        return DB::transaction(function () use ($rate, $actor): CurrencyConversionRate {
            $rate = CurrencyConversionRate::query()->lockForUpdate()->findOrFail($rate->id);
            $before = $rate->toArray();
            $rate->update(['status' => CurrencyConversionRate::STATUS_INACTIVE, 'enabled' => false]);
            $rate = $rate->fresh();
            $this->record($rate, $actor, 'DEACTIVATED', $before, $rate->toArray());
            AuditLogService::log('currency_rate_deactivated', (string) $rate->id, CurrencyConversionRate::class, contexte: ['from_currency' => $rate->payment_currency, 'to_currency' => $rate->wallet_currency], module: 'currency');

            return $rate;
        });
    }

    private function record(CurrencyConversionRate $rate, User $actor, string $action, ?array $before, array $after): void
    {
        CurrencyConversionRateHistory::create([
            'currency_conversion_rate_id' => $rate->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}