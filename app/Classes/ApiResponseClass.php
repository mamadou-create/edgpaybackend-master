<?php

namespace App\Classes;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\MessageBag;

class ApiResponseClass
{
    private const DEFAULT_ERROR_CODE = 'GENERIC_ERROR';

    private static function resolveCorrelationId(): string
    {
        $request = request();

        $fromAttribute = (string) $request->attributes->get('correlation_id', '');
        if ($fromAttribute !== '') {
            return $fromAttribute;
        }

        $fromHeader = (string) $request->header('X-Correlation-ID', '');
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        return (string) $request->header('X-Request-ID', 'N/A');
    }

    private static function defaultBusinessCode(int $code): string
    {
        return match ($code) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMITED',
            500 => 'INTERNAL_SERVER_ERROR',
            502 => 'BAD_GATEWAY',
            503 => 'SERVICE_UNAVAILABLE',
            504 => 'GATEWAY_TIMEOUT',
            default => self::DEFAULT_ERROR_CODE,
        };
    }

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
                "status_code" => JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
                "business_code" => 'INTERNAL_SERVER_ERROR',
                "message" => $message,
                "errors"  => $e->getMessage(),
                "data" => null,
                "correlation_id" => self::resolveCorrelationId(),
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
            'status_code' => $code,
            'message' => $message,
            'data'    => $result,
            'correlation_id' => self::resolveCorrelationId(),
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
    public static function sendError(
        string $message = "Erreur",
        $errors = null,
        int $code = JsonResponse::HTTP_BAD_REQUEST,
        ?string $businessCode = null
    ): JsonResponse
    {
        // Compatibilité rétroactive: certains contrôleurs appellent sendError('msg', 403)
        if (is_int($errors) && $code === JsonResponse::HTTP_BAD_REQUEST) {
            $code = $errors;
            $errors = null;
        }

        $response = [
            'success' => false,
            'status_code' => $code,
            'business_code' => $businessCode ?? self::defaultBusinessCode($code),
            'message' => $message,
            'errors'  => $errors,
            'data'    => null,
            'correlation_id' => self::resolveCorrelationId(),
        ];

        return response()->json($response, $code);
    }

    public static function validationError($arg1, $arg2 = 'Validation Error', ?int $code = null)
    {
        // Compatibilité: validationError($errors, 'msg') ET validationError('msg', $errors, 422)
        if (is_string($arg1) && (is_array($arg2) || $arg2 instanceof MessageBag)) {
            $message = $arg1;
            $errors = $arg2;
        } else {
            $errors = $arg1;
            $message = is_string($arg2) ? $arg2 : 'Validation Error';
        }

        $normalizedErrors = $errors instanceof MessageBag ? $errors->toArray() : $errors;
        $httpCode = $code ?? 422;

        return response()->json([
            'success' => false,
            'status_code' => $httpCode,
            'business_code' => 'VALIDATION_ERROR',
            'message' => $message,
            'errors' => $normalizedErrors,
            'data' => null,
            'correlation_id' => self::resolveCorrelationId(),
        ], $httpCode);
    }

    // Dans votre ApiResponseClass
    public static function sendAuthResponse($tokenData, $message = null, $code = 200)
    {
        $response = [
            'status' => true,
            'status_code' => $code,
            'access_token' => $tokenData['access_token'] ?? $tokenData,
            'token_type' => $tokenData['token_type'] ?? 'bearer',
            'expires_in' => $tokenData['expires_in'] ?? 3600,
            'message' => $message ?? 'Authentification réussie',
            'correlation_id' => self::resolveCorrelationId(),
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

    public static function serverError(
        string $message = "Erreur interne du serveur",
        int $code = JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
        ?string $businessCode = null
    ): JsonResponse
    {
        return self::sendError(
            $message,
            null,
            $code,
            $businessCode ?? self::defaultBusinessCode($code)
        );
    }
}
