<?php

namespace App\Http\Controllers\Reloadly;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Data\ReloadlyBillerDTO;
use App\Http\Resources\ReloadlyBillerResource;
use App\Http\Resources\ReloadlyUtilityTransactionHistoryResource;
use App\Models\ReloadlyUtilityTransaction;
use App\Interfaces\ReloadlyUtilityRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReloadlyUtilityController extends Controller
{
    public function __construct(private ReloadlyUtilityRepositoryInterface $utilities) {}
    public function listBillers(Request $request)
    {
        $result = $this->utilities->listBillers(
            $request->input('country_code'),
            $request->input('type'),
            $request->integer('page', 1),
            $request->integer('size', 20),
        );

        if (!$result['success']) {
            return ApiResponseClass::sendError($result['error'], null, $result['status']);
        }

        $content = is_array($result['data']['content'] ?? null) ? $result['data']['content'] : [];
        $billers = array_map(
            static fn (array $biller): array => (new ReloadlyBillerResource(
                ReloadlyBillerDTO::fromReloadly($biller),
            ))->resolve(),
            array_filter($content, 'is_array'),
        );

        return ApiResponseClass::sendResponse(['content' => $billers], 'Billers récupérés');
    }
    public function pay(Request $request)
    { $request->validate(['billerId' => 'required|integer', 'amount' => 'required|numeric|min:0.01', 'amountId' => 'nullable|integer', 'subscriberAccountNumber' => 'required|string', 'useLocalAmount' => 'nullable|boolean', 'paymentCurrency' => 'required|string|size:3']); $result = $this->utilities->payBill(['billerId' => $request->billerId, 'amount' => $request->amount, 'amountId' => $request->input('amountId'), 'subscriberAccountNumber' => $request->subscriberAccountNumber, 'useLocalAmount' => $request->boolean('useLocalAmount', false), 'paymentCurrency' => strtoupper($request->string('paymentCurrency')->toString())]); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], $result['message']) : ApiResponseClass::sendError($result['error'], $result['data'] ?? null, $result['status'], $result['data']['code'] ?? null); }
    public function paymentOptions(Request $request)
    { $request->validate(['billerId' => 'required|integer', 'amount' => 'required|numeric|min:0.01', 'amountId' => 'nullable|integer', 'useLocalAmount' => 'nullable|boolean']); $result = $this->utilities->paymentOptions(['billerId' => $request->integer('billerId'), 'amount' => $request->input('amount'), 'amountId' => $request->input('amountId'), 'useLocalAmount' => $request->boolean('useLocalAmount', false)]); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Options de paiement récupérées') : ApiResponseClass::sendError($result['error'], $result['data'] ?? null, $result['status'], $result['data']['code'] ?? null); }
    public function transactionStatus(int $transactionId)
    { $result = $this->utilities->getTransactionStatus($transactionId); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Statut récupéré') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function balance()
    { $result = $this->utilities->getBalance(); return $result['success'] ? ApiResponseClass::sendResponse($result['data'], 'Solde récupéré') : ApiResponseClass::sendError($result['error'], null, $result['status']); }
    public function history(Request $request)
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $result = $this->utilities->getTransactionHistory((string) Auth::id(), $perPage);

        if (!$result['success']) {
            return ApiResponseClass::sendError($result['error'], null, $result['status'] ?? 500);
        }

        $history = $result['data']->through(
            static fn (ReloadlyUtilityTransaction $transaction): array =>
                (new ReloadlyUtilityTransactionHistoryResource($transaction))->resolve(),
        );

        return ApiResponseClass::sendPaginatedResponse($history, 'Historique récupéré');
    }
}
