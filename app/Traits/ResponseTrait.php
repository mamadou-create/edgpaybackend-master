<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ResponseTrait
{
    /**
     * Réponse de succès générique
     */
    public function responseSuccess(
        $data = null, 
        string $message = "Successful", 
        int $status_code = JsonResponse::HTTP_OK
    ): JsonResponse {
        return response()->json([
            'status'  => true,
            'message' => $message,
            'errors'  => null,
            'data'    => $data,
        ], $status_code);
    }

    /**
     * Réponse d'erreur générique
     */
    public function responseError(
        $errors = null, 
        string $message = "Erreur", 
        int $status_code = JsonResponse::HTTP_BAD_REQUEST
    ): JsonResponse {
        return response()->json([
            'status'      => false,
            'message'     => $message,
            'errors'      => $errors,
            'data'        => null,
            'status_code' => $status_code,
        ], $status_code);
    }

    /**
     * Raccourcis pour les codes les plus utilisés
     */
    public function responseCreated($data = null, string $message = "Ressource créée avec succès"): JsonResponse
    {
        return $this->responseSuccess($data, $message, JsonResponse::HTTP_CREATED); // 201
    }

    public function responseNotFound(string $message = "Ressource introuvable"): JsonResponse
    {
        return $this->responseError(null, $message, JsonResponse::HTTP_NOT_FOUND); // 404
    }

    public function responseUnauthorized(string $message = "Non autorisé"): JsonResponse
    {
        return $this->responseError(null, $message, JsonResponse::HTTP_UNAUTHORIZED); // 401
    }

    public function responseForbidden(string $message = "Accès interdit"): JsonResponse
    {
        return $this->responseError(null, $message, JsonResponse::HTTP_FORBIDDEN); // 403
    }

    public function responseServerError(string $message = "Erreur interne du serveur"): JsonResponse
    {
        return $this->responseError(null, $message, JsonResponse::HTTP_INTERNAL_SERVER_ERROR); // 500
    }
}
