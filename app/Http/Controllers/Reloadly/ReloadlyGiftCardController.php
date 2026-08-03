<?php

namespace App\Http\Controllers\Reloadly;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Interfaces\ReloadlyGiftCardRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReloadlyGiftCardController extends Controller
{
    public function __construct(private ReloadlyGiftCardRepositoryInterface $giftcards) {}

    public function listProducts(Request $request)
    { $countryCode = $request->input('countryCode', $request->input('country_code')); $result = $this->giftcards->listProducts($request->integer('page', 1), $request->integer('size', 50), $countryCode); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Produits récupérés') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function getProduct(int $productId)
    { $result = $this->giftcards->getProduct($productId); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Produit récupéré') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function order(Request $request)
    {
        $request->validate([
            'productId' => 'required|integer',
            'unitPrice' => 'nullable|numeric|min:0.01',
            'base_amount' => 'nullable|numeric|min:0.01',
            'quantity' => 'nullable|integer|min:1',
            'recipientEmail' => 'nullable|email',
            'senderName' => 'nullable|string',
        ]);

        $unitPrice = (float) ($request->input('base_amount') ?? $request->input('unitPrice'));
        $quantity = $request->integer('quantity', 1);
        if ($unitPrice <= 0) {
            return ApiResponseClass::sendError('base_amount est requis.', null, 422);
        }

        $pricing = $this->calculateGiftCardPrice($unitPrice, $quantity);
        $result = $this->giftcards->orderGiftCard([
            'productId' => $request->integer('productId'),
            'unitPrice' => $unitPrice,
            'quantity' => $quantity,
            'baseAmount' => $pricing['base_amount'],
            'commissionAmount' => $pricing['commission_amount'],
            'walletAmount' => $pricing['wallet_amount'],
            'walletCurrency' => $pricing['wallet_currency'],
            'senderName' => $request->input('senderName'),
            'recipientEmail' => $request->input('recipientEmail'),
            'recipientPhoneDetails' => $request->input('recipientPhoneDetails'),
            'customIdentifier' => 'gc-' . Auth::id() . '-' . now()->timestamp,
        ]);

        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], $result['message'])
            : ApiResponseClass::sendError($result['error'], $result['data'] ?? null, $result['status']);
    }

    public function quote(Request $request)
    {
        $request->validate([
            'base_amount' => 'required|numeric|min:0.01',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $pricing = $this->calculateGiftCardPrice(
            (float) $request->input('base_amount'),
            $request->integer('quantity', 1),
        );
        $wallet = Auth::user()?->wallet;

        return ApiResponseClass::sendResponse([
            ...$pricing,
            'wallet_balance' => $wallet?->cash_available ?? 0,
            'wallet_currency' => $wallet?->currency ?? $pricing['wallet_currency'],
        ], 'Tarif calculé');
    }

    private function calculateGiftCardPrice(float $unitPrice, int $quantity): array
    {
        $baseAmount = round($unitPrice * $quantity, 2);
        $marginPercent = min(100, max(0, (float) config('services.reloadly.products.giftcards.margin_percent', 0)));
        $fixedFee = max(0, (float) config('services.reloadly.products.giftcards.margin_fixed', 0));
        $commissionAmount = round(($baseAmount * ($marginPercent / 100)) + $fixedFee, 2);
        $totalUserPrice = round($baseAmount + $commissionAmount, 2);
        $exchangeRate = max(0.000001, (float) config('services.reloadly.products.giftcards.usd_to_gnf', 1));

        return [
            'base_amount' => $baseAmount,
            'commission_rate' => $marginPercent / 100,
            'margin_percent' => $marginPercent,
            'commission_amount' => $commissionAmount,
            'total_user_price' => $totalUserPrice,
            'exchange_rate' => $exchangeRate,
            'wallet_amount' => (int) ceil($totalUserPrice * $exchangeRate),
            'wallet_currency' => config('services.reloadly.products.giftcards.wallet_currency', 'GNF'),
        ];
    }
    public function orderStatus(int $transactionId)
    { $result = $this->giftcards->getOrderStatus($transactionId); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Statut récupéré') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function redeemCode(int $transactionId)
    { $result = $this->giftcards->redeemCode($transactionId); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Codes récupérés') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function history(Request $request)
    { $result = $this->giftcards->getTransactionHistory((string) Auth::id(), $request->integer('per_page', 20)); return $result['success'] ? ApiResponseClass::sendPaginatedResponse($result['data'], 'Historique récupéré') : ApiResponseClass::sendError($result['error'], null, 500); }
}
