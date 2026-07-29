<?php

namespace App\Http\Controllers\Reloadly;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Interfaces\ReloadlyUtilityRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReloadlyUtilityController extends Controller
{
    public function __construct(private ReloadlyUtilityRepositoryInterface $utilities) {}
    public function listBillers(Request $request)
    { $result = $this->utilities->listBillers($request->input('country_code'), $request->input('type'), $request->integer('page', 1), $request->integer('size', 50)); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Billers récupérés') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function getBiller(int $billerId)
    { $result = $this->utilities->getBiller($billerId); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Biller récupéré') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function pay(Request $request)
    { $request->validate(['billerId' => 'required|integer', 'amount' => 'required|numeric|min:0.01', 'subscriberAccountNumber' => 'required|string', 'useLocalAmount' => 'nullable|boolean']); $result = $this->utilities->payBill(['billerId' => $request->billerId, 'amount' => $request->amount, 'subscriberAccountNumber' => $request->subscriberAccountNumber, 'useLocalAmount' => $request->boolean('useLocalAmount', false)]); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], $result['message']) : ApiResponseClass::sendError($result['error'], $result['data'] ?? null, $result['status']); }
    public function transactionStatus(int $transactionId)
    { $result = $this->utilities->getTransactionStatus($transactionId); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Statut récupéré') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function balance()
    { $result = $this->utilities->getBalance(); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Solde récupéré') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function history(Request $request)
    { $result = $this->utilities->getTransactionHistory((string) Auth::id(), $request->integer('per_page', 20)); return $result['success'] ? ApiResponseClass::sendPaginatedResponse($result['data'], 'Historique récupéré') : ApiResponseClass::sendError($result['error'], null, 500); }
}
