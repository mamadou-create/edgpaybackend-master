<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: [
            'prefix' => 'api/v1',
            'path' => __DIR__ . '/../routes/api.php',
        ],
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        channels: __DIR__.'/../routes/channels.php',
        attributes: ['prefix' => 'api', 'middleware' => ['auth:api']] 
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middleware global
        $middleware->append(\App\Http\Middleware\Cors::class);
        
        $middleware->alias([
            'check-admin'        => \App\Http\Middleware\CheckAdmin::class,
            'check-permission'   => \App\Http\Middleware\CheckPermission::class,
            'credit.profile'     => \App\Http\Middleware\CheckCreditProfile::class,
            'anti.replay'        => \App\Http\Middleware\AntiReplayMiddleware::class,
        ]);

        // Middleware personnalisé si besoin
        // $middleware->append(\App\Http\Middleware\Authenticate::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
