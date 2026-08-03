<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
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

        $userId = $request->user()?->getAuthIdentifier();
        $requestHash = hash('sha256', json_encode([
            'scope' => $scope,
            'user_id' => $userId,
            'method' => $request->method(),
            'path' => $request->path(),
            'payload' => $this->sortRecursively($request->all()),
        ], JSON_THROW_ON_ERROR));

        try {
            $record = DB::transaction(function () use ($idempotencyKey, $userId, $requestHash): IdempotencyKey {
                $existing = IdempotencyKey::query()
                    ->where('key', $idempotencyKey)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                return $existing ?? IdempotencyKey::create([
                    'key' => $idempotencyKey,
                    'user_id' => $userId,
                    'request_hash' => $requestHash,
                ]);
            });
        } catch (QueryException) {
            $record = IdempotencyKey::query()
                ->where('key', $idempotencyKey)
                ->where('user_id', $userId)
                ->first();
        }

        if ($record === null) {
            return $this->conflict('IDEMPOTENCY_RESERVATION_FAILED', 'Impossible de réserver la clé idempotente.');
        }
        if (!hash_equals($record->request_hash, $requestHash)) {
            return $this->conflict('IDEMPOTENCY_KEY_REUSED', 'La clé idempotente est déjà associée à une autre requête.');
        }
        if ($record->response_body !== null && $record->status_code !== null) {
            return response()->json($record->response_body, $record->status_code, ['X-Idempotency-Key' => $idempotencyKey]);
        }
        if (!$record->wasRecentlyCreated) {
            return $this->conflict('IDEMPOTENCY_REQUEST_IN_PROGRESS', 'Une requête identique est déjà en cours de traitement.');
        }

        $request->attributes->set('idempotency_key', $idempotencyKey);

        $response = $next($request);
        $body = json_decode($response->getContent(), true);
        $record->update([
            'response_body' => is_array($body) ? $body : ['raw' => $response->getContent()],
            'status_code' => $response->getStatusCode(),
        ]);
        $response->headers->set('X-Idempotency-Key', $idempotencyKey);

        return $response;
    }

    private function conflict(string $businessCode, string $message): Response
    {
        return response()->json([
            'success' => false,
            'status_code' => 409,
            'error' => $businessCode,
            'business_code' => $businessCode,
            'message' => $message,
            'errors' => null,
            'data' => null,
            'correlation_id' => (string) (
                request()->attributes->get('correlation_id')
                ?? request()->header('X-Correlation-ID')
                ?? 'N/A'
            ),
        ], 409);
    }

    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursively($item);
            }
        }

        ksort($value);

        return $value;
    }

}
