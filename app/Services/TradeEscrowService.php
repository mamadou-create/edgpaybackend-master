<?php

namespace App\Services;

use App\Interfaces\WalletRepositoryInterface;
use App\Models\TradeEscrow;
use App\Models\TradeOffer;
use Illuminate\Support\Facades\DB;

class TradeEscrowService
{
    public function __construct(private readonly WalletRepositoryInterface $walletRepository)
    {
    }

    public function block(TradeOffer $offer, int $amount, ?string $reason = null, ?array $metadata = null): TradeEscrow
    {
        return DB::transaction(function () use ($offer, $amount, $reason, $metadata) {
            $payerWallet = $this->walletRepository->getByUserId((string) $offer->proposer_id);
            $payeeWallet = $this->walletRepository->getByUserId((string) $offer->recipient_id);

            if (!$payerWallet || !$payeeWallet) {
                throw new \RuntimeException('Wallet introuvable pour initialiser l\'escrow.');
            }

            $this->walletRepository->blockAmount((string) $payerWallet->id, $amount);

            $escrow = TradeEscrow::query()->updateOrCreate(
                ['trade_offer_id' => (string) $offer->id],
                [
                    'payer_user_id' => (string) $offer->proposer_id,
                    'payee_user_id' => (string) $offer->recipient_id,
                    'payer_wallet_id' => (string) $payerWallet->id,
                    'payee_wallet_id' => (string) $payeeWallet->id,
                    'amount' => $amount,
                    'status' => TradeEscrow::STATUS_BLOCKED,
                    'reason' => $reason,
                    'metadata' => $metadata,
                    'blocked_at' => now(),
                    'released_at' => null,
                    'cancelled_at' => null,
                    'disputed_at' => null,
                    'resolved_at' => null,
                ]
            );

            return $escrow;
        });
    }

    public function release(TradeEscrow $escrow, ?string $reason = null, ?array $metadata = null): TradeEscrow
    {
        return DB::transaction(function () use ($escrow, $reason, $metadata) {
            if ($escrow->status !== TradeEscrow::STATUS_BLOCKED) {
                throw new \RuntimeException('Seul un escrow bloqué peut être libéré.');
            }

            $this->walletRepository->unblockAndWithdraw((string) $escrow->payer_wallet_id, (int) $escrow->amount);
            $this->walletRepository->credit((string) $escrow->payee_wallet_id, (float) $escrow->amount);

            $escrow->status = TradeEscrow::STATUS_RELEASED;
            $escrow->released_at = now();
            $escrow->resolved_at = now();
            $escrow->reason = $reason ?? $escrow->reason;
            $escrow->metadata = $metadata ?? $escrow->metadata;
            $escrow->save();

            return $escrow;
        });
    }

    public function cancel(TradeEscrow $escrow, ?string $reason = null, ?array $metadata = null): TradeEscrow
    {
        return DB::transaction(function () use ($escrow, $reason, $metadata) {
            if (!in_array($escrow->status, [TradeEscrow::STATUS_BLOCKED, TradeEscrow::STATUS_DISPUTED], true)) {
                throw new \RuntimeException('Seul un escrow bloqué ou en litige peut être annulé.');
            }

            $this->walletRepository->unblockAmount((string) $escrow->payer_wallet_id, (int) $escrow->amount);

            $escrow->status = TradeEscrow::STATUS_CANCELLED;
            $escrow->cancelled_at = now();
            $escrow->resolved_at = now();
            $escrow->reason = $reason ?? $escrow->reason;
            $escrow->metadata = $metadata ?? $escrow->metadata;
            $escrow->save();

            return $escrow;
        });
    }

    public function markDisputed(TradeEscrow $escrow, ?string $reason = null, ?array $metadata = null): TradeEscrow
    {
        $escrow->status = TradeEscrow::STATUS_DISPUTED;
        $escrow->disputed_at = now();
        $escrow->reason = $reason ?? $escrow->reason;
        $escrow->metadata = $metadata ?? $escrow->metadata;
        $escrow->save();

        return $escrow;
    }
}
