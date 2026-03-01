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

class Handler extends ExceptionHandler
{
    /**
     * Gérer le rendu des exceptions.
     */
    public function render($request, Throwable $e)
    {
        // 401 - Non authentifié
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'success'     => false,
                'status_code' => 401,
                'message'     => 'Non authentifié. Veuillez vous connecter pour accéder à cette ressource.',
            ], 401);
        }

        // 403 - Accès refusé
        if ($e instanceof AuthorizationException) {
            return response()->json([
                'success'     => false,
                'status_code' => 403,
                'message'     => 'Accès refusé. Vous n’avez pas les droits nécessaires.',
            ], 403);
        }

        // 404 - Ressource introuvable
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return response()->json([
                'success'     => false,
                'status_code' => 404,
                'message'     => 'Ressource introuvable.',
            ], 404);
        }

        // 405 - Méthode HTTP non autorisée
        if ($e instanceof MethodNotAllowedHttpException) {
            return response()->json([
                'success'     => false,
                'status_code' => 405,
                'message'     => 'Méthode HTTP non autorisée.',
            ], 405);
        }

        // 422 - Erreurs de validation
        if ($e instanceof ValidationException) {
            return response()->json([
                'success'     => false,
                'status_code' => 422,
                'message'     => 'Erreur de validation.',
                'errors'      => $e->errors(),
            ], 422);
        }

        // Erreurs HTTP génériques
        if ($e instanceof HttpException) {
            return response()->json([
                'success'     => false,
                'status_code' => $e->getStatusCode(),
                'message'     => $e->getMessage() ?: 'Erreur HTTP.',
            ], $e->getStatusCode());
        }

        // 500 - Erreur serveur
        return response()->json([
            'success'     => false,
            'status_code' => 500,
            'message'     => 'Erreur interne du serveur.',
            'error'       => config('app.debug') ? $e->getMessage() : null, // afficher le détail en debug
        ], 500);
    }
}
