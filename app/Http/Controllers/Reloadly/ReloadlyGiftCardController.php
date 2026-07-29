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
    { $result = $this->giftcards->listProducts($request->integer('page', 1), $request->integer('size', 50), $request->input('country_code')); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Produits récupérés') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function getProduct(int $productId)
    { $result = $this->giftcards->getProduct($productId); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Produit récupéré') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function order(Request $request)
    { $request->validate(['productId' => 'required|integer', 'unitPrice' => 'required|numeric|min:0.01', 'quantity' => 'nullable|integer|min:1', 'recipientEmail' => 'nullable|email', 'senderName' => 'nullable|string']); $result = $this->giftcards->orderGiftCard(['productId' => $request->productId, 'unitPrice' => $request->unitPrice, 'quantity' => $request->integer('quantity', 1), 'senderName' => $request->senderName, 'recipientEmail' => $request->recipientEmail, 'recipientPhoneDetails' => $request->recipientPhoneDetails, 'customIdentifier' => 'gc-' . Auth::id() . '-' . now()->timestamp]); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], $result['message']) : ApiResponseClass::sendError($result['error'], $result['data'] ?? null, $result['status']); }
    public function orderStatus(int $transactionId)
    { $result = $this->giftcards->getOrderStatus($transactionId); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Statut récupéré') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function redeemCode(int $transactionId)
    { $result = $this->giftcards->redeemCode($transactionId); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Codes récupérés') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function history(Request $request)
    { $result = $this->giftcards->getTransactionHistory((string) Auth::id(), $request->integer('per_page', 20)); return $result['success'] ? ApiResponseClass::sendPaginatedResponse($result['data'], 'Historique récupéré') : ApiResponseClass::sendError($result['error'], null, 500); }
}
