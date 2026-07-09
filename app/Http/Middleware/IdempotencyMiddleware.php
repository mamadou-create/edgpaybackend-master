<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    private const CACHE_PREFIX = 'idempotency:';

    public function handle(Request $request, Closure $next, string $scope = 'default', int $ttl = 300): Response
    {
        $mutatingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        if (!in_array($request->getMethod(), $mutatingMethods, true)) {
            return $next($request);
        }

        $idempotencyKey = trim((string) $request->header('X-Idempotency-Key', ''));
        if (strlen($idempotencyKey) < 10) {
            return response()->json([
                'success' => false,
                'status_code' => 422,
                'business_code' => 'IDEMPOTENCY_KEY_REQUIRED',
                'message' => 'Header X-Idempotency-Key requis pour cette opération.',
                'errors' => [
                    'X-Idempotency-Key' => ['La clé idempotente est requise et doit contenir au moins 10 caractères.'],
                ],
                'data' => null,
                'correlation_id' => (string) (
                    $request->attributes->get('correlation_id')
                    ?? $request->header('X-Correlation-ID')
                    ?? 'N/A'
                ),
            ], 422);
        }

        $actor = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());
        $fingerprint = implode('|', [
            $scope,
            $actor,
            $request->method(),
            $request->path(),
            $idempotencyKey,
        ]);

        $cacheKey = self::CACHE_PREFIX . hash('sha256', $fingerprint);
        $reserved = Cache::add($cacheKey, true, $ttl);

        if (!$reserved) {
            return response()->json([
                'success' => false,
                'status_code' => 409,
                'business_code' => 'IDEMPOTENT_REPLAY_DETECTED',
                'message' => 'Requête dupliquée détectée. Utilisez une nouvelle clé idempotente.',
                'errors' => null,
                'data' => null,
                'correlation_id' => (string) (
                    $request->attributes->get('correlation_id')
                    ?? $request->header('X-Correlation-ID')
                    ?? 'N/A'
                ),
            ], 409);
        }

        $request->attributes->set('idempotency_key', $idempotencyKey);

        $response = $next($request);
        $response->headers->set('X-Idempotency-Key', $idempotencyKey);

        return $response;
    }
}
