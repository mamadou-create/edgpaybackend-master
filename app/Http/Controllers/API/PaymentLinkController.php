<?php
// app/Http/Controllers/API/PaymentLinkController.php

namespace App\Http\Controllers\API;

use App\Classes\ApiResponseClass;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentLinkResource;
use App\Interfaces\DjomyServiceInterface;
use App\Models\PaymentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentLinkController extends Controller
{
    public function __construct(private DjomyServiceInterface $djomyService) {}

    /**
     * 📋 Lister tous les liens de paiement
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $links = PaymentLink::with('user')->paginate($perPage);

        return ApiResponseClass::sendResponse(
            PaymentLinkResource::collection($links),
            'Liste des liens de paiement récupérée avec succès'
        );
    }

    /**
     * 🔍 Afficher un lien de paiement
     */
    public function show(string $id): JsonResponse
    {
        $link = PaymentLink::with('user')->find($id);

        if (!$link) {
            return ApiResponseClass::sendError(
                'Lien de paiement non trouvé.',
                [],
                Response::HTTP_NOT_FOUND
            );
        }

        return ApiResponseClass::sendResponse(
            new PaymentLinkResource($link),
            'Lien de paiement récupéré avec succès'
        );
    }

    /**
     * 🔄 Vérifier le statut d'un lien de paiement via Djomy
     */
    public function status(string $externalLinkId): JsonResponse
    {
        $result = $this->djomyService->getLink($externalLinkId);

        if (!$result['success']) {
            return ApiResponseClass::sendError(
                $result['error'] ?? 'Erreur lors de la récupération du statut',
                $result['data'] ?? [],
                $result['status'] ?? Response::HTTP_BAD_REQUEST
            );
        }

        return ApiResponseClass::sendResponse(
            new PaymentLinkResource($result['payment_link']),
            'Statut du lien de paiement récupéré avec succès'
        );
    }
}
