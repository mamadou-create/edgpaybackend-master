<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Models\TradeEscrow;
use App\Models\TradeOffer;
use App\Models\User;
use App\Services\TradeEscrowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TradeEscrowController extends Controller
{
    public function __construct(private readonly TradeEscrowService $service)
    {
    }

    public function show(TradeOffer $tradeOffer): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if (!$this->canAccessOffer($user, $tradeOffer)) {
            return ApiResponseClass::forbidden('Accès refusé à cet escrow.');
        }

        $escrow = $tradeOffer->escrow;

        return ApiResponseClass::sendResponse(
            ['escrow' => $escrow ? $this->serializeEscrow($escrow) : null],
            'Escrow récupéré avec succès'
        );
    }

    public function block(Request $request, TradeOffer $tradeOffer): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if (!$this->canManageEscrow($user, $tradeOffer)) {
            return ApiResponseClass::forbidden('Vous n\'êtes pas autorisé à bloquer les fonds.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $escrow = $this->service->block(
                offer: $tradeOffer,
                amount: (int) $validated['amount'],
                reason: $validated['reason'] ?? null,
                metadata: $validated['metadata'] ?? null,
            );
        } catch (\Throwable $e) {
            return ApiResponseClass::sendError($e->getMessage(), null, 422);
        }

        return ApiResponseClass::sendResponse(
            ['escrow' => $this->serializeEscrow($escrow)],
            'Fonds bloqués avec succès'
        );
    }

    public function release(Request $request, TradeOffer $tradeOffer): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if (!$this->canManageEscrow($user, $tradeOffer)) {
            return ApiResponseClass::forbidden('Vous n\'êtes pas autorisé à libérer les fonds.');
        }

        $escrow = $tradeOffer->escrow;
        if (!$escrow) {
            return ApiResponseClass::notFound('Escrow introuvable pour cette offre.');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $escrow = $this->service->release(
                escrow: $escrow,
                reason: $validated['reason'] ?? null,
                metadata: $validated['metadata'] ?? null,
            );
        } catch (\Throwable $e) {
            return ApiResponseClass::sendError($e->getMessage(), null, 422);
        }

        return ApiResponseClass::sendResponse(
            ['escrow' => $this->serializeEscrow($escrow)],
            'Fonds libérés avec succès'
        );
    }

    public function dispute(Request $request, TradeOffer $tradeOffer): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if (!$this->canAccessOffer($user, $tradeOffer)) {
            return ApiResponseClass::forbidden('Vous n\'êtes pas autorisé à signaler un litige.');
        }

        $escrow = $tradeOffer->escrow;
        if (!$escrow) {
            return ApiResponseClass::notFound('Escrow introuvable pour cette offre.');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $escrow = $this->service->markDisputed(
            escrow: $escrow,
            reason: $validated['reason'] ?? null,
            metadata: $validated['metadata'] ?? null,
        );

        return ApiResponseClass::sendResponse(
            ['escrow' => $this->serializeEscrow($escrow)],
            'Litige enregistré. Fonds maintenus bloqués.'
        );
    }

    public function cancel(Request $request, TradeOffer $tradeOffer): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponseClass::unauthorized('Utilisateur non authentifié.');
        }
        /** @var User $user */

        if (!$this->canManageEscrow($user, $tradeOffer)) {
            return ApiResponseClass::forbidden('Vous n\'êtes pas autorisé à annuler cet escrow.');
        }

        $escrow = $tradeOffer->escrow;
        if (!$escrow) {
            return ApiResponseClass::notFound('Escrow introuvable pour cette offre.');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $escrow = $this->service->cancel(
                escrow: $escrow,
                reason: $validated['reason'] ?? null,
                metadata: $validated['metadata'] ?? null,
            );
        } catch (\Throwable $e) {
            return ApiResponseClass::sendError($e->getMessage(), null, 422);
        }

        return ApiResponseClass::sendResponse(
            ['escrow' => $this->serializeEscrow($escrow)],
            'Escrow annulé et fonds débloqués'
        );
    }

    private function canAccessOffer(User $user, TradeOffer $offer): bool
    {
        $isSuperAdmin = (bool) ($user->role?->is_super_admin ?? false);

        return $isSuperAdmin
            || (string) $offer->proposer_id === (string) $user->id
            || (string) $offer->recipient_id === (string) $user->id;
    }

    private function canManageEscrow(User $user, TradeOffer $offer): bool
    {
        $isSuperAdmin = (bool) ($user->role?->is_super_admin ?? false);

        return $isSuperAdmin || (string) $offer->recipient_id === (string) $user->id;
    }

    private function serializeEscrow(TradeEscrow $escrow): array
    {
        return [
            'id' => (string) $escrow->id,
            'trade_offer_id' => (string) $escrow->trade_offer_id,
            'payer_user_id' => (string) $escrow->payer_user_id,
            'payee_user_id' => (string) $escrow->payee_user_id,
            'payer_wallet_id' => (string) $escrow->payer_wallet_id,
            'payee_wallet_id' => (string) $escrow->payee_wallet_id,
            'amount' => (int) $escrow->amount,
            'status' => $escrow->status,
            'reason' => $escrow->reason,
            'metadata' => $escrow->metadata,
            'blocked_at' => $escrow->blocked_at?->toIso8601String(),
            'released_at' => $escrow->released_at?->toIso8601String(),
            'cancelled_at' => $escrow->cancelled_at?->toIso8601String(),
            'disputed_at' => $escrow->disputed_at?->toIso8601String(),
            'resolved_at' => $escrow->resolved_at?->toIso8601String(),
            'created_at' => $escrow->created_at?->toIso8601String(),
            'updated_at' => $escrow->updated_at?->toIso8601String(),
        ];
    }
}
