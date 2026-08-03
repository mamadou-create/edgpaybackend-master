<?php

namespace App\Http\Controllers\Reloadly;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReloadlyTransactionResource;
use App\Interfaces\ReloadlyRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReloadlyController extends Controller
{
    public function __construct(private ReloadlyRepositoryInterface $reloadly) {}

    public function searchOperator(Request $request)
    {
        $request->validate([
            'phone'        => 'required|string',
            'country_code' => 'required|string|size:2',
        ]);

        $result = $this->reloadly->searchOperator($request->phone, $request->country_code);

        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], 'Opérateur trouvé')
            : ApiResponseClass::sendError($result['error'], null, $result['status']);
    }

    public function topup(Request $request)
    {
        $request->validate([
            'operatorId'                 => 'required|integer',
            'amount'                     => 'required|numeric|min:0.01',
            'useLocalAmount'            => 'boolean',
            'recipientPhone.number'      => 'required|string',
            'recipientPhone.countryCode' => 'required|string|size:2',
        ]);

        // Récupérer l'opérateur pour avoir ses limites
        $operator = $this->reloadly->searchOperator(
            $request->recipientPhone['number'],
            $request->recipientPhone['countryCode']
        );

        if (!$operator['success']) {
            return ApiResponseClass::sendError('Opérateur introuvable', null, $operator['status']);
        }

        $opData = $operator['data'];

        // Vérifier les limites selon la devise
        $useLocalAmount = $request->boolean('useLocalAmount', true);

        if ($useLocalAmount) {
            $min = $opData['localMinAmount'] ?? null;
            $max = $opData['localMaxAmount'] ?? null;
            if ($min !== null && $request->amount < $min) {
                return ApiResponseClass::sendError("Le montant minimum est de {$min} GNF");
            }
            if ($max !== null && $request->amount > $max) {
                return ApiResponseClass::sendError("Le montant maximum est de {$max} GNF");
            }

            if (($opData['denominationType'] ?? null) !== 'RANGE') {
                $fixedAmounts = $opData['fixedAmounts'] ?? [];
                if (is_array($fixedAmounts) && $fixedAmounts !== []) {
                    $isAllowedAmount = collect($fixedAmounts)->contains(
                        fn ($fixedAmount) => abs((float) $fixedAmount - (float) $request->amount) < 0.01
                    );
                    if (!$isAllowedAmount) {
                        return ApiResponseClass::sendError(
                            'Le montant choisi ne fait pas partie des montants autorisés en GNF.'
                        );
                    }
                }
            }
        } else {
            $min = $opData['minAmount'] ?? null;
            $max = $opData['maxAmount'] ?? null;
            if ($min !== null && $request->amount < $min) {
                return ApiResponseClass::sendError("Le montant minimum est de {$min} USD");
            }
            if ($max !== null && $request->amount > $max) {
                return ApiResponseClass::sendError("Le montant maximum est de {$max} USD");
            }
        }

        // Continuer avec le topup
        $result = $this->reloadly->makeTopup([
            'operatorId'       => $request->operatorId,
            'amount'           => $request->amount,
            'useLocalAmount'   => $useLocalAmount,
            'customIdentifier' => 'topup-' . Auth::id() . '-' . now()->timestamp,
            'recipientEmail'   => $request->recipientEmail,
            'recipientPhone'   => $request->recipientPhone,
        ]);

        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], $result['message'])
            : ApiResponseClass::sendError($result['error'], $result['data'] ?? null, $result['status']);
    }

    // Lister les forfaits d’un opérateur
    public function operatorProducts(int $operatorId)
    {
        $result = $this->reloadly->getOperatorProducts($operatorId);
        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], 'Produits disponibles')
            : ApiResponseClass::sendError($result['error'], null, $result['status']);
    }


    // Acheter un forfait data
    public function buyDataBundle(Request $request)
    {
        $request->validate([
            'operatorId'                 => 'required|integer',
            'productId'                  => 'required|integer',
            'recipientPhone.number'      => 'required|string',
            'recipientPhone.countryCode' => 'required|string|size:2',
        ]);

        $data = [
            'operatorId'     => $request->operatorId,
            'productId'      => $request->productId,
            'recipientPhone' => $request->recipientPhone,
            'recipientEmail' => $request->recipientEmail,
            'customIdentifier' => 'data-' . Auth::id() . '-' . now()->timestamp,
        ];

        $result = $this->reloadly->makeTopup($data);

        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], 'Forfait data activé')
            : ApiResponseClass::sendError($result['error'], $result['data'] ?? null, $result['status']);
    }

    public function operatorsByCountry(string $countryCode)
    {
        $result = $this->reloadly->getOperatorsByCountry($countryCode);

        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], 'Opérateurs récupérés')
            : ApiResponseClass::sendError($result['error'], null, $result['status']);
    }

    public function getDataPlans(int $operatorId)
    {
        $result = $this->reloadly->getDataPlans($operatorId);
        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], 'Forfaits data récupérés')
            : ApiResponseClass::sendError($result['error'], null, $result['status']);
    }

    public function getPromotions(int $operatorId)
    {
        $result = $this->reloadly->getPromotions($operatorId);
        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], 'Promotions récupérées')
            : ApiResponseClass::sendError($result['error'], null, $result['status']);
    }

    public function getCommissions(Request $request)
    {
        $result = $this->reloadly->getCommissions($request->integer('operatorId'));
        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], 'Commissions récupérées')
            : ApiResponseClass::sendError($result['error'], null, $result['status']);
    }

    public function verifyTransaction(string $transactionId)
    {
        $result = $this->reloadly->verifyTransaction($transactionId);
        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], 'Transaction vérifiée')
            : ApiResponseClass::sendError($result['error'], null, $result['status']);
    }

    public function creditWallet(Request $request)
    {
        $request->validate([
            'amount'      => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $result = $this->reloadly->creditWallet(
            (int) $request->amount,
            $request->description
        );

        return $result['success']
            ? ApiResponseClass::sendResponse(null, $result['message'])
            : ApiResponseClass::sendError($result['error'], null, 500);
    }

    public function balance()
    {
        $result = $this->reloadly->getBalance();

        return $result['success']
            ? ApiResponseClass::sendResponse($result['data'], 'Solde récupéré')
            : ApiResponseClass::sendError($result['error'], null, $result['status']);
    }

    public function history(Request $request)
    {
        $result = $this->reloadly->getTransactionHistory(
            (string) Auth::id(),
            $request->integer('per_page', 20)
        );

        if ($result['success']) {
            // Utilisation de la ressource pour formater la collection paginée
            $collection = ReloadlyTransactionResource::collection($result['data']);
            return ApiResponseClass::sendPaginatedResponse($collection, 'Historique récupéré');
        }

        return ApiResponseClass::sendError($result['error'], null, 500);
    }
}
