<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Interfaces\DjomyServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DjomyController extends Controller
{
    public function __construct(private DjomyServiceInterface $djomyService) {}

    /**
     * 💳 Créer un paiement direct (sans redirection)
     */
    public function createPayment(Request $request): JsonResponse
    {
        // ✅ Validation en snake_case
        $validator = Validator::make($request->all(), [
            'payment_method' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (!in_array($value, array_column(PaymentMethod::cases(), 'value'))) {
                        $fail("La méthode de paiement '$value' n'est pas valide.");
                    }
                },
            ],
            'payer_identifier' => 'required|string',
            'amount' => 'required|numeric|min:20000',
            'country_code' => 'required|string|size:2',
            'currency_code' => 'required|string|size:3',
            'description' => 'required|string|max:255',
            'service_type' => 'required|string|max:100',
            'compteur_id' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Données de paiement invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // 🔄 Conversion snake_case → camelCase pour l’API externe
        $payload = collect($validator->validated())->mapWithKeys(function ($value, $key) {
            return [Str::camel($key) => $value];
        })->toArray();

        $result = $this->djomyService->createPayment($payload);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la création du paiement.',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Paiement créé et enregistré avec succès.'
        );
    }

    /**
     * 💳 Créer un paiement avec redirection (gateway)
     */
    public function createPaymentWithGateway(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:20000',
            'country_code' => 'required|string|size:2',
            'payer_number' => 'sometimes|string',
            'description' => 'required|string|max:255',
            'service_type' => 'required|string|max:100',
            'compteur_id' => 'required|string|max:100',
            'return_url' => 'sometimes|url',
            'cancel_url' => 'sometimes|url',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données de paiement gateway invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Conversion snake_case → camelCase
        $payload = collect($validator->validated())->mapWithKeys(fn($value, $key) => [Str::camel($key) => $value])->toArray();

        //dd($payload);

        $result = $this->djomyService->createPaymentWithGateway($payload);


        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la création du paiement gateway',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Paiement gateway créé avec succès'
        );
    }

    /**
     * 🔎 Obtenir le statut d'un paiement
     */
    public function getPaymentStatus(string $paymentId): JsonResponse
    {
        $validator = Validator::make(['payment_id' => $paymentId], [
            'payment_id' => 'required|string|min:1',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'ID de paiement invalide.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->djomyService->getPaymentStatus($paymentId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la récupération du statut',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Statut du paiement récupéré avec succès'
        );
    }

    /**
     * 🔎 Obtenir le statut d'un paiement
     */
    public function getPaymentLinkStatus(string $paymentId): JsonResponse
    {
        $validator = Validator::make(['payment_id' => $paymentId], [
            'payment_id' => 'required|string|min:1',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'ID de paiement invalide.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->djomyService->getPaymentLinkStatus($paymentId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la récupération du statut',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Statut du paiement récupéré avec succès'
        );
    }

    /**
     * 🔗 Générer un lien de paiement
     */
    public function generateLink(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount_to_pay' => 'required|numeric|min:1',
            'link_name' => 'required|string|max:255',
            'phone_number' => 'required|string',
            'description' => 'required|string|max:255',
            'country_code' => 'required|string|size:2',
            'payment_link_usage_type' => 'required|string|in:UNIQUE,MULTIPLE',
            'expires_at' => 'sometimes|date|after:now',
            'date_from' => 'sometimes|date',
            'valid_until' => 'sometimes|date|after:date_from',
            'custom_fields' => 'sometimes|array',
            'custom_fields.*.label' => 'required_with:custom_fields|string',
            'custom_fields.*.placeholder' => 'required_with:custom_fields|string',
            'custom_fields.*.required' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données de lien de paiement invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Conversion snake_case → camelCase pour l’API externe
        $payload = collect($validator->validated())->mapWithKeys(fn($value, $key) => [Str::camel($key) => $value])->toArray();

        $result = $this->djomyService->generateLink($payload);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la génération du lien',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Lien de paiement généré avec succès'
        );
    }

    /**
     * 🔎 Obtenir les détails d'un lien
     */
    public function getLink(string $linkId): JsonResponse
    {
        $result = $this->djomyService->getLink($linkId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la récupération du lien',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Lien récupéré avec succès'
        );
    }

    /**
     * 🔗 Lister tous les liens
     */
    public function getLinks(Request $request): JsonResponse
    {
        $result = $this->djomyService->getLinks();

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la récupération des liens',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Liste des liens récupérée avec succès'
        );
    }

    /**
     * 🔑 Authentification Djomy
     */
    public function authenticate(): JsonResponse
    {
        $result = $this->djomyService->authenticate();

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur d\'authentification Djomy',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_UNAUTHORIZED
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Authentification Djomy réussie'
        );
    }

    /**
     * 💡 Vérifier la santé de l'API
     */
    public function healthCheck(): JsonResponse
    {
        try {
            $authResult = $this->djomyService->authenticate();

            if ($authResult['success']) {
                return ApiResponseClass::sendResponse(
                    ['status' => 'healthy', 'authenticated' => true],
                    'API Djomy opérationnelle'
                );
            }

            return ApiResponseClass::sendError(
                'API Djomy accessible mais erreur d\'authentification',
                $authResult['data'] ?? [],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        } catch (\Exception $e) {
            return ApiResponseClass::sendError(
                'API Djomy inaccessible: ' . $e->getMessage(),
                [],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }
    }

     /**
     * 🎯 Point de retour (callback) de Djomy pour le statut du paiement
     */
    public function status(Request $request)
    {
        Log::info('Djomy status callback received', [
            'payload' => $request->all(),
        ]);

        try {
            $transactionId = $request->input('transaction_id');

            if (!$transactionId) {
                Log::error('Missing transaction_id in Djomy callback', [
                    'request' => $request->all(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Identifiant de paiement manquant',
                ], 400);
            }

            // Appel du service
            $result = $this->djomyService->getPaymentStatus($transactionId);

            return response()->json($result, $result['status'] ?? 200);
        } catch (\Throwable $e) {
            Log::error('Error while processing Djomy status callback', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur interne lors du traitement du statut',
            ], 500);
        }
    }

    /**
     * ❌ Annuler un paiement
     */
    public function cancel(Request $request)
    {
        $transactionId = $request->input('transaction_id');

        $result = $this->djomyService->cancelPayment($transactionId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de l’annulation du paiement.',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Paiement annulé avec succès.'
        );
    }




     /**
     * 💳 Créer un paiement avec redirection (gateway)
     */
    public function createPaymentWithGatewayEcommerce(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:20000',
            'country_code' => 'required|string|size:2',
            'payer_number' => 'sometimes|string',
            'description' => 'required|string|max:255',
            'return_url' => 'sometimes|url',
            'cancel_url' => 'sometimes|url',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données de paiement gateway invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Conversion snake_case → camelCase
        $payload = collect($validator->validated())->mapWithKeys(fn($value, $key) => [Str::camel($key) => $value])->toArray();

        //dd($payload);

        $result = $this->djomyService->createPaymentWithGatewayExternal($payload);


        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la création du paiement gateway',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Paiement gateway créé avec succès'
        );
    }

    /**
     * 🔎 Obtenir le statut d'un paiement
     */
    public function getPaymentStatusEcommerce(string $paymentId): JsonResponse
    {
        $validator = Validator::make(['payment_id' => $paymentId], [
            'payment_id' => 'required|string|min:1',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'ID de paiement invalide.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->djomyService->getPaymentStatus($paymentId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['message'] ?? 'Erreur lors de la récupération du statut',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            $result['data'],
            'Statut du paiement récupéré avec succès'
        );
    }
}
