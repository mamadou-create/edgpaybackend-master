<?php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentCollection;
use App\Http\Resources\PaymentResource;
use App\Interfaces\DjomyServiceInterface;
use App\Interfaces\PaymentRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    public function __construct(
        private DjomyServiceInterface $djomyService,
        private PaymentRepositoryInterface $paymentRepository
    ) {}

    /**
     * 📋 Lister tous les paiements
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'sometimes|integer|min:1|max:100',
            'status' => 'sometimes|string|in:pending,success,failed,cancelled',
            'payment_method' => 'sometimes|string|in:gateway,mobile_money,card',
            'user_id' => 'sometimes|uuid|exists:users,id',
            'service_type' => 'sometimes|string|in:prepaid,postpayment',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'min_amount' => 'sometimes|numeric|min:0',
            'max_amount' => 'sometimes|numeric|min:0',
            'search' => 'sometimes|string|max:255',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Paramètres de recherche invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $perPage = $request->get('per_page', 15);
        $filters = $request->only([
            'status',
            'payment_method',
            'user_id',
            'service_type',
            'start_date',
            'end_date',
            'min_amount',
            'max_amount'
        ]);

        try {
            if ($request->has('search')) {
                $payments = $this->paymentRepository->search($request->search, $filters, $perPage);
            } else {
                $payments = $this->paymentRepository->getAllPaginated($filters, $perPage);
            }

            return ApiResponseClass::sendResponse(
                new PaymentCollection($payments),
                'Liste des paiements récupérée avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::sendError(
                'Erreur lors de la récupération des paiements.',
                $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
    /**
     * 📊 Obtenir les statistiques des paiements
     */
    public function stats(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'user_id' => 'sometimes|uuid|exists:users,id',
            'status' => 'sometimes|string|in:' . implode(',', array_column(PaymentStatus::cases(), 'value')),
            'payment_method' => 'sometimes|string|in:' . implode(',', array_column(PaymentMethod::cases(), 'value')),
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::validationError(
                'Paramètres de statistiques invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $stats = $this->paymentRepository->getStats($request->all());

        return ApiResponseClass::sendResponse(
            $stats,
            'Statistiques des paiements récupérées avec succès'
        );
    }

    /**
     * 🔍 Afficher un paiement spécifique
     */
    public function show(string $id): JsonResponse
    {
        $payment = $this->paymentRepository->findByIdOrReference($id);

        if (!$payment) {
            return ApiResponseClass::sendError(
                'Paiement non trouvé.',
                [],
                Response::HTTP_NOT_FOUND
            );
        }

        $statusHistory = $this->paymentRepository->getStatusHistory($payment->id);

        return ApiResponseClass::sendResponse(
            [
                'payment' => new PaymentResource($payment),
                'status_history' => $statusHistory,
                'raw_data' => [
                    'request' => $payment->raw_request,
                    'response' => $payment->raw_response,
                ],
            ],
            'Détails du paiement récupérés avec succès'
        );
    }

    /**
     * 🆕 Créer un nouveau paiement (manuellement)
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'merchant_payment_reference' => 'required|string|max:100|unique:payments,merchant_payment_reference',
            'payer_identifier' => 'required|string',
            'payment_method' => 'required|string|in:' . implode(',', array_column(PaymentMethod::cases(), 'value')),
            'amount' => 'required|numeric|min:0.01',
            'country_code' => 'required|string|size:2',
            'currency_code' => 'required|string|size:3',
            'description' => 'required|string|max:255',
            'status' => 'sometimes|string|in:' . implode(',', array_column(PaymentStatus::cases(), 'value')),
            'external_reference' => 'sometimes|string|nullable',
            'gateway_url' => 'sometimes|url|nullable',
            'user_id' => 'sometimes|uuid|exists:users,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données de paiement invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $data = $request->all();
        $data['user_id'] = $data['user_id'] ?? auth()->id;
        $data['status'] = $data['status'] ?? PaymentStatus::PENDING->value;

        try {
            $payment = $this->paymentRepository->create($data);

            return ApiResponseClass::sendResponse(
                new PaymentResource($payment),
                'Paiement créé avec succès',
                Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return ApiResponseClass::sendError(
                'Erreur lors de la création du paiement: ' . $e->getMessage(),
                [],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * ✏️ Mettre à jour un paiement
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $payment = $this->paymentRepository->findById($id);

        if (!$payment) {
            return ApiResponseClass::sendError(
                'Paiement non trouvé.',
                [],
                Response::HTTP_NOT_FOUND
            );
        }

        $validator = Validator::make($request->all(), [
            'payer_identifier' => 'sometimes|string',
            'payment_method' => 'sometimes|string|in:' . implode(',', array_column(PaymentMethod::cases(), 'value')),
            'amount' => 'sometimes|numeric|min:0.01',
            'country_code' => 'sometimes|string|size:2',
            'currency_code' => 'sometimes|string|size:3',
            'description' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|in:' . implode(',', array_column(PaymentStatus::cases(), 'value')),
            'external_reference' => 'sometimes|string|nullable',
            'gateway_url' => 'sometimes|url|nullable',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données de mise à jour invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // Ne pas permettre la modification de la référence marchand
        $data = $request->except(['merchant_payment_reference', 'user_id']);

        try {
            $updated = $this->paymentRepository->update($id, $data);

            if (!$updated) {
                return ApiResponseClass::sendError(
                    'Erreur lors de la mise à jour du paiement.',
                    [],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }

            $payment = $this->paymentRepository->findById($id);

            return ApiResponseClass::sendResponse(
                new PaymentResource($payment),
                'Paiement mis à jour avec succès'
            );
        } catch (\Exception $e) {
            return ApiResponseClass::sendError(
                'Erreur lors de la mise à jour du paiement: ' . $e->getMessage(),
                [],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * 🗑️ Supprimer un paiement (soft delete)
     */
    public function destroy(string $id): JsonResponse
    {
        $payment = $this->paymentRepository->findById($id);

        if (!$payment) {
            return ApiResponseClass::sendError(
                'Paiement non trouvé.',
                [],
                Response::HTTP_NOT_FOUND
            );
        }

        $deleted = $this->paymentRepository->delete($id);

        if (!$deleted) {
            return ApiResponseClass::sendError(
                'Erreur lors de la suppression du paiement.',
                [],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return ApiResponseClass::sendResponse(
            [],
            'Paiement supprimé avec succès'
        );
    }

    /**
     * 🔄 Restaurer un paiement supprimé
     */
    public function restore(string $id): JsonResponse
    {
        $payment = $this->paymentRepository->findById($id);

        if (!$payment) {
            return ApiResponseClass::sendError(
                'Paiement non trouvé.',
                [],
                Response::HTTP_NOT_FOUND
            );
        }

        $restored = $this->paymentRepository->restore($id);

        if (!$restored) {
            return ApiResponseClass::sendError(
                'Erreur lors de la restauration du paiement.',
                [],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $payment = $this->paymentRepository->findById($id);

        return ApiResponseClass::sendResponse(
            new PaymentResource($payment),
            'Paiement restauré avec succès'
        );
    }

    /**
     * 💥 Supprimer définitivement un paiement
     */
    public function forceDelete(string $id): JsonResponse
    {
        $payment = $this->paymentRepository->findById($id);

        if (!$payment) {
            return ApiResponseClass::sendError(
                'Paiement non trouvé.',
                [],
                Response::HTTP_NOT_FOUND
            );
        }

        $deleted = $this->paymentRepository->forceDelete($id);

        if (!$deleted) {
            return ApiResponseClass::sendError(
                'Erreur lors de la suppression définitive du paiement.',
                [],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return ApiResponseClass::sendResponse(
            [],
            'Paiement supprimé définitivement avec succès'
        );
    }

    /**
     * 🔢 Compter les paiements par statut
     */
    public function countByStatus(string $status): JsonResponse
    {
        if (!in_array($status, array_column(PaymentStatus::cases(), 'value'))) {
            return ApiResponseClass::sendError(
                'Statut invalide.',
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $count = $this->paymentRepository->countByStatus($status);

        return ApiResponseClass::sendResponse(
            ['count' => $count],
            "Nombre de paiements avec le statut {$status}"
        );
    }

    /**
     * 🔢 Compter les paiements par méthode de paiement
     */
    public function countByPaymentMethod(string $paymentMethod): JsonResponse
    {
        if (!in_array($paymentMethod, array_column(PaymentMethod::cases(), 'value'))) {
            return ApiResponseClass::sendError(
                'Méthode de paiement invalide.',
                [],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $count = $this->paymentRepository->countByPaymentMethod($paymentMethod);

        return ApiResponseClass::sendResponse(
            ['count' => $count],
            "Nombre de paiements avec la méthode {$paymentMethod}"
        );
    }

    /**
     * 💰 Obtenir le montant total des paiements
     */
    public function totalAmount(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'user_id' => 'sometimes|uuid|exists:users,id',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Paramètres invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $totalAmount = $this->paymentRepository->getTotalAmount($request->all());

        return ApiResponseClass::sendResponse(
            ['total_amount' => $totalAmount],
            'Montant total des paiements récupéré avec succès'
        );
    }

    /**
     * 🔍 Vérifier si une référence marchand existe
     */
    public function checkMerchantReference(string $merchantReference): JsonResponse
    {
        $exists = $this->paymentRepository->merchantReferenceExists($merchantReference);

        return ApiResponseClass::sendResponse(
            ['exists' => $exists],
            $exists ? 'La référence marchand existe déjà' : 'La référence marchand est disponible'
        );
    }

    /**
     * 🔄 Récupérer les paiements échoués pour nouvelle tentative
     */
    public function failedPaymentsForRetry(): JsonResponse
    {
        $payments = $this->paymentRepository->getFailedPaymentsForRetry();

        return ApiResponseClass::sendResponse(
            PaymentResource::collection($payments),
            'Paiements échoués récupérés avec succès'
        );
    }

    /**
     * 💳 Créer un paiement direct (sans redirection) - Djomy
     */
    public function createPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'paymentMethod' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (!in_array($value, array_column(PaymentMethod::cases(), 'value'))) {
                        $fail("La méthode de paiement '$value' n'est pas valide.");
                    }
                },
            ],
            'payerIdentifier' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'countryCode' => 'required|string|size:2',
            'currencyCode' => 'required|string|size:3',
            'description' => 'required|string|max:255',
            'merchantPaymentReference' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données de paiement invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->djomyService->createPayment($request->all());

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
     * 🌍 Créer un paiement avec redirection vers gateway - Djomy
     */
    public function createPaymentWithGateway(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'countryCode' => 'required|string|size:2',
            'payerNumber' => 'sometimes|string',
            'description' => 'required|string|max:255',
            'merchantPaymentReference' => 'required|string|max:100',
            'returnUrl' => 'sometimes|url',
            'cancelUrl' => 'sometimes|url',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données de paiement gateway invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->djomyService->createPaymentWithGateway($request->all());

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la création du paiement gateway',
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
     * 🔎 Obtenir le statut d'un paiement - Djomy
     */
    public function getPaymentStatus(string $paymentId): JsonResponse
    {
        $result = $this->djomyService->getPaymentStatus($paymentId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la récupération du statut',
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
     * 🔗 Générer un lien de paiement - Djomy
     */
    public function generateLink(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amountToPay' => 'required|numeric|min:1',
            'linkName' => 'required|string|max:255',
            'phoneNumber' => 'required|string',
            'description' => 'required|string|max:255',
            'countryCode' => 'required|string|size:2',
            'paymentLinkUsageType' => 'required|string|in:UNIQUE,MULTIPLE',
            'expiresAt' => 'sometimes|date|after:now',
            'dateFrom' => 'sometimes|date',
            'validUntil' => 'sometimes|date|after:dateFrom',
            'customFields' => 'sometimes|array',
            'customFields.*.label' => 'required_with:customFields|string',
            'customFields.*.placeholder' => 'required_with:customFields|string',
            'customFields.*.required' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return ApiResponseClass::sendError(
                'Données de lien de paiement invalides.',
                $validator->errors(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $result = $this->djomyService->generateLink($request->all());

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la génération du lien',
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
     * 🔑 Authentification Djomy
     */
    public function authenticate(Request $request): JsonResponse
    {
        $result = $this->djomyService->authenticate();

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur d\'authentification Djomy',
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
     * 🩺 Vérifier la santé de l'API Djomy
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
            } else {
                return ApiResponseClass::sendError(
                    'API Djomy accessible mais erreur d\'authentification',
                    $authResult['data'] ?? [],
                    Response::HTTP_SERVICE_UNAVAILABLE
                );
            }
        } catch (\Exception $e) {
            return ApiResponseClass::sendError(
                'API Djomy inaccessible: ' . $e->getMessage(),
                [],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }
    }
}
