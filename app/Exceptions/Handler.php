<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;

class Handler extends ExceptionHandler
{
    private function correlationId($request): string
    {
        return (string) (
            $request->attributes->get('correlation_id')
            ?? $request->header('X-Correlation-ID')
            ?? $request->header('X-Request-ID')
            ?? 'N/A'
        );
    }

    private function apiErrorResponse(
        $request,
        int $statusCode,
        string $message,
        string $businessCode,
        mixed $errors = null,
        mixed $debug = null
    ): JsonResponse {
        $payload = [
            'success' => false,
            'status_code' => $statusCode,
            'business_code' => $businessCode,
            'message' => $message,
            'errors' => $errors,
            'data' => null,
            'correlation_id' => $this->correlationId($request),
        ];

        if ($debug !== null) {
            $payload['debug'] = $debug;
        }

        return response()->json($payload, $statusCode);
    }

    /**
     * Gérer le rendu des exceptions.
     */
    public function render($request, Throwable $e)
    {
        // 401 - Non authentifié
        if ($e instanceof AuthenticationException) {
            return $this->apiErrorResponse(
                $request,
                401,
                'Non authentifié. Veuillez vous connecter pour accéder à cette ressource.',
                'AUTH_UNAUTHENTICATED'
            );
        }

        // 403 - Accès refusé
        if ($e instanceof AuthorizationException) {
            return $this->apiErrorResponse(
                $request,
                403,
                'Accès refusé. Vous n’avez pas les droits nécessaires.',
                'AUTH_FORBIDDEN'
            );
        }

        // 404 - Ressource introuvable
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return $this->apiErrorResponse(
                $request,
                404,
                'Ressource introuvable.',
                'RESOURCE_NOT_FOUND'
            );
        }

        // 405 - Méthode HTTP non autorisée
        if ($e instanceof MethodNotAllowedHttpException) {
            return $this->apiErrorResponse(
                $request,
                405,
                'Méthode HTTP non autorisée.',
                'METHOD_NOT_ALLOWED'
            );
        }

        // 422 - Erreurs de validation
        if ($e instanceof ValidationException) {
            return $this->apiErrorResponse(
                $request,
                422,
                'Erreur de validation.',
                'VALIDATION_ERROR',
                $e->errors()
            );
        }

        // Erreurs HTTP génériques
        if ($e instanceof HttpException) {
            return $this->apiErrorResponse(
                $request,
                $e->getStatusCode(),
                $e->getMessage() ?: 'Erreur HTTP.',
                'HTTP_ERROR'
            );
        }

        // 500 - Erreur serveur
        return $this->apiErrorResponse(
            $request,
            500,
            'Erreur interne du serveur.',
            'INTERNAL_SERVER_ERROR',
            null,
            config('app.debug') ? $e->getMessage() : null
        );
    }
}
