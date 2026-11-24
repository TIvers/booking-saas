<?php

use App\Http\Middleware\EnsureTenantManager;
use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetPostgresTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Tenant context must be set before Sanctum session reads and route model binding.
        $middleware->prependToGroup('api', [
            SetPostgresTenantContext::class,
            ResolveTenant::class,
        ]);
        $middleware->alias([
            'tenant.member' => EnsureTenantMembership::class,
            'tenant.manager' => EnsureTenantManager::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
