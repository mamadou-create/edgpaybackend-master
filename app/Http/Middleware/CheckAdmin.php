<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckAdmin
{
    /**
     * Gérer une requête entrante.
     *
     * @param  string|null  $type  (optionnel : 'finance', 'commercial', etc.)
     */
    public function handle(Request $request, Closure $next, ?string $type = null): Response
    {
       $user = Auth::user();

        // 🔒 Vérification d'authentification
        if (!$user) {
            return response()->json([
                'success' => false,
                'status_code' => 401,
                'message' => 'Non authentifié. Veuillez vous connecter.',
            ], 401);
        }

        // 👑 Le Super Admin a accès à tout
        if (method_exists($user, 'isSuperAdmin') && $this->isSuperAdmin($user)) {
            return $next($request);
        }

        // 🔹 Vérification : est-ce un sous-admin ?
        if (!method_exists($user, 'isSubAdmin') || !$this->isSubAdmin($user)) {
            return response()->json([
                'success' => false,
                'status_code' => 403,
                'message' => 'Accès réservé aux administrateurs.',
            ], 403);
        }

        // 🔸 Vérification du type spécifique de sous-admin (finance, commercial, etc.)
        if ($type && method_exists($user, 'role')) {
            if ($user->role !== $type && $user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'message' => "Type d'admin non autorisé pour cette ressource.",
                ], 403);
            }
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
     * Vérifie si l'utilisateur est Super Admin
     */
    private function isSubAdmin($user): bool
    {
        // Essayer différentes méthodes pour vérifier le Super Admin
        if (method_exists($user, 'isSubAdmin') && $user->isSubAdmin()) {
            return true;
        }

        if (method_exists($user, 'getIsSubAdminAttribute')) {
            return $user->is_sub_admin; // Utiliser l'accesseur correct
        }

        if (property_exists($user, 'role') && $user->role) {
            return $user->role->is_super_admin ?? false;
        }

        return false;
    }
}
