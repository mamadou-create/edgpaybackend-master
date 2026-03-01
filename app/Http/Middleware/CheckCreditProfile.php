<?php

namespace App\Http\Middleware;

use App\Models\CreditProfile;
use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de vérification du profil de crédit.
 *
 * Protège les endpoints qui requièrent :
 *   - Un profil de crédit initialisé
 *   - Un compte non bloqué
 *   - Un score minimum (configurable)
 */
class CheckCreditProfile
{
    public function handle(Request $request, Closure $next, int $scoreMin = 0): Response
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié.'], 401);
        }

        $profil = $user->creditProfile;

        if (! $profil) {
            return response()->json([
                'success' => false,
                'message' => 'Profil de crédit non initialisé. Contactez votre gestionnaire de compte.',
            ], 403);
        }

        if ($profil->estActuellementBloque()) {
            AuditLogService::tentativeInvalide('acces_compte_bloque', [
                'user_id'       => $user->id,
                'motif_blocage' => $profil->motif_blocage,
                'url'           => $request->fullUrl(),
            ]);

            return response()->json([
                'success'       => false,
                'message'       => 'Compte bloqué : ' . ($profil->motif_blocage ?? 'raison non spécifiée'),
                'est_bloque'    => true,
                'bloque_jusqu_au' => $profil->bloque_jusqu_au,
            ], 403);
        }

        if ($scoreMin > 0 && $profil->score_fiabilite < $scoreMin) {
            return response()->json([
                'success' => false,
                'message' => sprintf(
                    'Score de fiabilité insuffisant (%d/%d requis).',
                    $profil->score_fiabilite,
                    $scoreMin
                ),
            ], 403);
        }

        return $next($request);
    }
}
