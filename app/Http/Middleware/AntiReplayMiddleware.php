<?php

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protection Anti-Replay Attack.
 *
 * Chaque requête de paiement doit porter un header X-Idempotency-Key unique.
 * Si la clé a déjà été utilisée dans la fenêtre de validité, la requête est rejetée.
 *
 * Usage dans les routes :
 *   Route::middleware('anti.replay:paiement,300')
 *   Le deuxième paramètre est la durée TTL en secondes (défaut : 300s = 5 min).
 */
class AntiReplayMiddleware
{
    private const CACHE_PREFIX = 'anti_replay:';

    public function handle(Request $request, Closure $next, string $contexte = 'default', int $ttl = 300): Response
    {
        $key = $request->header('X-Idempotency-Key');

        if (! $key || strlen($key) < 10) {
            AuditLogService::tentativeInvalide('replay_attack_no_key', [
                'url'    => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Header X-Idempotency-Key requis pour cette opération.',
            ], 422);
        }

        $cacheKey = self::CACHE_PREFIX . $contexte . ':' . hash('sha256', $key);

        if (Cache::has($cacheKey)) {
            AuditLogService::tentativeInvalide('replay_attack_detected', [
                'contexte'      => $contexte,
                'idempotency_key' => $key,
                'url'           => $request->fullUrl(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Requête dupliquée détectée (anti-replay). Utilisez une nouvelle clé X-Idempotency-Key.',
            ], 409);
        }

        // Enregistrer la clé pour la durée TTL
        Cache::put($cacheKey, true, $ttl);

        // Exposer la clé pour les services en aval
        $request->merge(['_idempotency_key' => $key]);

        return $next($request);
    }
}
