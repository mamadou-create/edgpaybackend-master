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
            $profil = $user->creditProfile()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'credit_limite' => 0,
                    'credit_disponible' => 0,
                    'score_fiabilite' => 100,
                    'niveau_risque' => 'faible',
                ]
            );
        }

        if ($profil->estActuellementBloque()) {
            // Autoriser la consultation (lecture seule) même si le compte est bloqué.
            // Objectif: laisser le client visualiser ses créances / transactions,
            // tout en bloquant les actions sensibles (paiements, etc.).
            $actionMethod = $request->route()?->getActionMethod();
            $isReadOnlyCreditView = $request->isMethod('GET')
                && in_array($actionMethod, ['mesCreances', 'mesCreanceDetail', 'mesTransactions'], true);

            if ($isReadOnlyCreditView) {
                $request->attributes->set('credit_account_blocked', true);
                return $next($request);
            }

            AuditLogService::tentativeInvalide('acces_compte_bloque', [
                'user_id'       => $user->id,
                'motif_blocage' => $profil->motif_blocage,
                'url'           => $request->fullUrl(),
            ]);

            return response()->json([
                'success'         => false,
                'message'         => 'Compte bloqué : ' . ($profil->motif_blocage ?? 'raison non spécifiée'),
                'est_bloque'      => true,
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
