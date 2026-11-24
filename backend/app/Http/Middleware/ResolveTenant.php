<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/onboarding')) {
            return $next($request);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $tenant = is_int($tenantId) ? Tenant::query()->find($tenantId) : null;

        if ($tenant === null) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        app()->instance('currentTenant', $tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
