<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Définir où rediriger les utilisateurs non authentifiés.
     */
    protected function redirectTo($request)
    {
        abort(response()->json([
            'success' => false,
            'status_code' => 401,
            'message' => 'Non authentifié. Veuillez vous connecter pour accéder à cette ressource.',
        ], 401));
    }
}
