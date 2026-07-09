<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Correlation-ID', '');
        $correlationId = $incoming !== '' ? $incoming : (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);
        Log::withContext([
            'correlation_id' => $correlationId,
            'request_path' => $request->path(),
            'request_method' => $request->method(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
