<?php

namespace App\Classes;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class ApiResponseClass
{
    /**
     * Rollback d'une transaction et génération d'une erreur
     */
    public static function rollback(\Throwable $e, string $message = "Une erreur s'est produite ! Le processus n'a pas été mené à bien."): void
    {
        DB::rollBack();
        self::throw($e, $message);
    }

    /**
     * Génération d'une erreur avec log et exception HTTP
     */
    public static function throw(\Throwable $e, string $message = "Une erreur s'est produite ! Le processus n'a pas été mené à bien."): void
    {
        Log::error($e->getMessage(), [
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);

        throw new HttpResponseException(
            response()->json([
                "success" => false,
                "message" => $message,
                "errors"  => $e->getMessage()
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR)
        );
    }

    /**
     * Réponse de succès générique
     */
    public static function sendResponse($result, string $message = "Succès", int $code = JsonResponse::HTTP_OK): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data'    => $result
        ];

        return response()->json(
            $response,
            $code,
            [],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Réponse d'erreur générique
     */
    public static function sendError(string $message = "Erreur", $errors = null, int $code = JsonResponse::HTTP_BAD_REQUEST): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
            'data'    => null
        ];

        return response()->json($response, $code);
    }

    public static function validationError($errors, $message = 'Validation Error')
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors->toArray(),
            'data' => null
        ], 422);
    }

    // Dans votre ApiResponseClass
    public static function sendAuthResponse($tokenData, $message = null, $code = 200)
    {
        $response = [
            'status' => true,
            'access_token' => $tokenData['access_token'] ?? $tokenData,
            'token_type' => $tokenData['token_type'] ?? 'bearer',
            'expires_in' => $tokenData['expires_in'] ?? 3600,
            'message' => $message ?? 'Authentification réussie',
            'status_code' => $code
        ];

        return response()->json($response, $code);
    }


    /**
     * Raccourcis pour les statuts HTTP les plus fréquents
     */
    public static function created($data, string $message = "Ressource créée avec succès"): JsonResponse
    {
        return self::sendResponse($data, $message, JsonResponse::HTTP_CREATED);
    }

    public static function notFound(string $message = "Ressource introuvable"): JsonResponse
    {
        return self::sendError($message, null, JsonResponse::HTTP_NOT_FOUND);
    }

    public static function unauthorized(string $message = "Non autorisé"): JsonResponse
    {
        return self::sendError($message, null, JsonResponse::HTTP_UNAUTHORIZED);
    }

    public static function forbidden(string $message = "Accès interdit"): JsonResponse
    {
        return self::sendError($message, null, JsonResponse::HTTP_FORBIDDEN);
    }

    public static function serverError(string $message = "Erreur interne du serveur"): JsonResponse
    {
        return self::sendError($message, null, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
    }
}
