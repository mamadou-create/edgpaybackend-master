<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Origin, Content-Type, Accept, Authorization, X-Requested-With',
        ];

        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);

            foreach ($corsHeaders as $name => $value) {
                $response->headers->set($name, $value);
            }

            return $response;
        }

        $response = $next($request);

        foreach ($corsHeaders as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}