<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Mail\PaymentFailedMail;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\DmlService;
use App\Models\DmlTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class DmlController extends Controller
{
    private $dmlService;

    public function __construct(DmlService $dmlService)
    {
        $this->dmlService = $dmlService;
    }

    /**
     * Authentification DML
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'telephone' => 'required|string',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de connexion invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->dmlService->authenticate($request->all());

        if (!$result['status']) {
            return ApiResponseClass::validationError(
                $result['error'] ?? 'Échec de l\'authentification',
                [],
                $result['status_code'] ?? Response::HTTP_UNAUTHORIZED
            );
        }

        return ApiResponseClass::sendAuthResponse($result, $result['message']);
    }

    /**
     * Recherche client prépayé
     */
    public function searchPrepaidCustomer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rst_value' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de recherche invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->dmlService->searchPrepaidCustomer($request->all());

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la recherche du client prépayé',
                [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse($result['data'], $result['message'] ?? 'Client trouvé avec succès');
    }

    /**
     * Sauvegarder transaction prépayée
     */
    public function savePrepaidTransaction(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rst_value' => 'required|string',
            'amt' => 'required|numeric|min:20000',
            'code' => 'required|string',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'buy_last_date' => 'required|date',
            'payment_id' => 'sometimes',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de transaction invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $data = $request->all();

        $result = $this->dmlService->processPrepaidTransaction($data);

        if (!$result['success']) {
            // Notification mail d'échec de paiement prépayé
            $authUser = Auth::guard()->user();
            $failedMail = new PaymentFailedMail(
                $authUser->display_name ?? $authUser->phone ?? 'utilisateur',
                (int) $request->input('amt', 0),
                $request->input('rst_value', '—'),
                $result['error'] ?? 'Erreur inconnue',
                'Prépayé (DML)'
            );
            try {
                // Envoi à l'utilisateur
                if ($authUser && !empty($authUser->email)) {
                    Mail::to($authUser->email)->send($failedMail);
                }
                // Envoi à tous les super-admins
                $superAdmins = User::whereHas('role', fn($q) => $q->where('is_super_admin', true))
                    ->whereNotNull('email')->get();
                foreach ($superAdmins as $admin) {
                    Mail::to($admin->email)->send($failedMail);
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur envoi PaymentFailedMail (prépayé) : ' . $mailException->getMessage());
            }

            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la sauvegarde de la transaction',
                [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse($result['data'], $result['message'] ?? 'Transaction sauvegardée avec succès');
    }

    /**
     * Recherche client postpayé
     */
    public function searchPostPaymentCustomer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rst_code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de recherche invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->dmlService->searchPostPaymentCustomer($request->all());

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la recherche du client postpayé',
                [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse($result['data'], $result['message'] ?? 'Client trouvé avec succès');
    }

    /**
     * Sauvegarder transaction postpayée
     */
    public function savePostPaymentTransaction(Request $request): JsonResponse
    {

        // Log::info('💰 DML Controller - Début sauvegarde transaction postpayment', [
        //     'endpoint' => $request->path(),
        //     'method' => $request->method(),
        //     'client_ip' => $request->ip(),
        //     'user_agent' => $request->userAgent(),
        // ]);

        // // Log des données brutes reçues
        // Log::debug('📥 DML Controller - Données POST reçues', $request->all());

        $validator = Validator::make($request->all(), [
            'rst_value' => 'required|string',
            'code' => 'required|string',
            'name' => 'required|string|max:255',
            'device' => 'required|string',
            'amt' => 'required',
            'montant' => 'required|numeric|min:1000',
            'phone' => 'required|string|max:20',
            'total_arrear' => 'required|numeric|min:0',
            'payment_id' => 'sometimes',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de transaction invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }
        // Log::info('✅ DML Controller - Validation réussie', [
        //     'montant' => $request->input('montant'),
        //     'rst_value' => $request->input('rst_value'),
        //     'name' => $request->input('name'),
        //     'phone' => $request->input('phone'),
        //     'user_id' => auth()->id ?? 'non authentifié',
        // ]);

        $data = $request->all();

        $result = $this->dmlService->processPostPaymentTransaction($data);

        if (!$result['success']) {
            // Notification mail d'échec de paiement postpayé
            $authUser = Auth::guard()->user();
            $failedMail = new PaymentFailedMail(
                $authUser->display_name ?? $authUser->phone ?? 'utilisateur',
                (int) $request->input('montant', 0),
                $request->input('rst_value', '—'),
                $result['error'] ?? 'Erreur inconnue',
                'Postpayé (DML)'
            );
            try {
                // Envoi à l'utilisateur
                if ($authUser && !empty($authUser->email)) {
                    Mail::to($authUser->email)->send($failedMail);
                }
                // Envoi à tous les super-admins
                $superAdmins = User::whereHas('role', fn($q) => $q->where('is_super_admin', true))
                    ->whereNotNull('email')->get();
                foreach ($superAdmins as $admin) {
                    Mail::to($admin->email)->send($failedMail);
                }
            } catch (\Throwable $mailException) {
                Log::error('Erreur envoi PaymentFailedMail (postpayé) : ' . $mailException->getMessage());
            }

            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la sauvegarde de la transaction',
                [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse($result['data'], $result['message'] ?? 'Transaction sauvegardée avec succès');
    }

    /**
     * Obtenir une transaction
     */
    public function getTransaction(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ref_facture' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données de recherche invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $data = [
            'ref_facture' => $request->ref_facture,
        ];

        $result = $this->dmlService->checkTransactionStatus($data);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la récupération de la transaction',
                [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse($result['data'], $result['message'] ?? 'Transaction récupérée avec succès');
    }

    /**
     * Obtenir le solde
     */
    public function getBalance(): JsonResponse
    {
        $result = $this->dmlService->getAccountBalance();

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la récupération du solde',
                [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse($result['data'], $result['message'] ?? 'Solde récupéré avec succès');
    }

    /**
     * Historique des transactions DML
     */
    public function getTransactionHistory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'sometimes|integer|min:1|max:1000',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Paramètres de pagination invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $perPage = $request->get('per_page', 20);
        $user = Auth::guard()->user();

        try {
            $transactions = DmlTransaction::with(['user', 'payment'])->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return ApiResponseClass::sendResponse($transactions, 'Historique récupéré avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::sendError(
                'Erreur lors de la récupération de l\'historique',
                [],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Historique des transactions DML
     */
    public function getTransactionHistoryAdmin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'sometimes|integer|min:1|max:1000',
            'user_id' => 'sometimes|uuid|exists:users,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Paramètres de pagination invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $perPage = $request->get('per_page', 20);

        try {
            $query = DmlTransaction::with(['user', 'payment']);

            // Filtre par user_id si présent
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            $transactions = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return ApiResponseClass::sendResponse($transactions, 'Historique récupéré avec succès');
        } catch (\Exception $e) {
            return ApiResponseClass::sendError(
                'Erreur lors de la récupération de l\'historique',
                [],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Synchroniser les transactions DML
     */
    public function syncTransactions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Dates de synchronisation invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->dmlService->syncTransactions($request->all());

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la synchronisation',
                [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse($result['data'], $result['message'] ?? 'Synchronisation démarrée avec succès');
    }

    /**
     * Générer un rapport d'activité DML
     */
    public function generateReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_id' => 'sometimes|uuid|exists:users,id'
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Critères de rapport invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->dmlService->generateActivityReport($request->all());

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la génération du rapport',
                [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse($result['data'], $result['message'] ?? 'Rapport généré avec succès');
    }
}
