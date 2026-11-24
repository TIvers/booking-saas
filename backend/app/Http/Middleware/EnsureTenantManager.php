<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membership = $user === null
            ? null
            : Membership::query()->where('user_id', $user->getAuthIdentifier())->first();

        if ($membership === null || ! $membership->role->canManageTenant()) {
            return response()->json(['message' => 'You are not allowed to manage this tenant.'], 403);
        }

        return $next($request);
    }
}
