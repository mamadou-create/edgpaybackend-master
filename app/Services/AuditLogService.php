<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Service d'audit trail.
 * Enregistre chaque action sensible de façon immuable.
 */
class AuditLogService
{
    // Actions prédéfinies
    public const ACTION_CREATION_CREANCE         = 'creation_creance';
    public const ACTION_VALIDATION_PAIEMENT      = 'validation_paiement';
    public const ACTION_REJET_PAIEMENT           = 'rejet_paiement';
    public const ACTION_MODIFICATION_LIMITE      = 'modification_limite_credit';
    public const ACTION_BLOCAGE_COMPTE           = 'blocage_compte';
    public const ACTION_DEBLOCAGE_COMPTE         = 'deblocage_compte';
    public const ACTION_RECALCUL_SCORE           = 'recalcul_score';
    public const ACTION_ANOMALIE_DETECTEE        = 'anomalie_detectee';
    public const ACTION_ANOMALIE_RESOLUE         = 'anomalie_resolue';
    public const ACTION_TENTATIVE_INVALIDE       = 'tentative_invalide';
    public const ACTION_SUPPRESSION_TENTATIVE    = 'suppression_tentative';

    /**
     * Enregistre un événement d'audit.
     *
     * @param string      $action        Identifiant action
     * @param string|null $cibleId       UUID entité affectée
     * @param string|null $cibleType     Classe entité affectée
     * @param string      $resultat      succes|echec|tentative
     * @param array|null  $avant         Données avant modification
     * @param array|null  $apres         Données après modification
     * @param array|null  $contexte      Données additionnelles
     * @param string      $module        Module applicatif
     */
    public static function log(
        string  $action,
        ?string $cibleId   = null,
        ?string $cibleType = null,
        string  $resultat  = 'succes',
        ?array  $avant     = null,
        ?array  $apres     = null,
        ?array  $contexte  = null,
        string  $module    = 'credit'
    ): void {
        try {
            $request = request();
            $userId = Auth::id();

            $sessionId = null;
            try {
                if ($request instanceof Request && $request->hasSession()) {
                    $sessionId = $request->session()->getId();
                }
            } catch (\Throwable) {
                $sessionId = null;
            }

            AuditLog::create([
                'user_id'       => $userId,
                'cible_id'      => $cibleId,
                'cible_type'    => $cibleType,
                'action'        => $action,
                'module'        => $module,
                'resultat'      => $resultat,
                'donnees_avant' => $avant,
                'donnees_apres' => $apres,
                'contexte'      => $contexte,
                'ip_address'    => $request?->ip(),
                'user_agent'    => $request?->userAgent(),
                'session_id'    => $sessionId,
            ]);
        } catch (\Throwable $e) {
            // Ne jamais provoquer une erreur à cause de l'audit
            Log::error('[AuditLog] Échec enregistrement audit', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Enregistre une tentative suspecte ou invalide.
     */
    public static function tentativeInvalide(
        string $action,
        array  $contexte = []
    ): void {
        self::log(
            $action,
            resultat: 'tentative',
            contexte: $contexte,
            module: 'security'
        );
    }
}
