<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Gérer une requête entrante.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();

        // 🔐 Vérification de l'authentification
        if (!$user) {
            return response()->json([
                'success' => false,
                'status_code' => 401,
                'message' => 'Non authentifié. Veuillez vous connecter pour accéder à cette ressource.',
            ], 401);
        }

        // 👑 Vérification plus robuste pour Super Admin
        if ($this->isSuperAdmin($user)) {
            return $next($request);
        }

        // 🚫 Vérification de la permission
        if (!$this->hasPermission($user, $permission)) {
            return response()->json([
                'success' => false,
                'status_code' => 403,
                'message' => 'Permission insuffisante pour accéder à cette ressource.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Vérifie si l'utilisateur est Super Admin
     */
    private function isSuperAdmin($user): bool
    {
        // Essayer différentes méthodes pour vérifier le Super Admin
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (method_exists($user, 'getIsSuperAdminAttribute')) {
            return $user->is_super_admin; // Utiliser l'accesseur
        }

        if (property_exists($user, 'role') && $user->role) {
            return $user->role->is_super_admin ?? false;
        }

        return false;
    }

    /**
     * Vérifie si l'utilisateur a la permission
     */
    private function hasPermission($user, string $permission): bool
    {
        if (!method_exists($user, 'hasPermission')) {
            return false;
        }

        return $user->hasPermission($permission);
    }
}