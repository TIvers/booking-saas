<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! Membership::query()->where('user_id', $user->getAuthIdentifier())->exists()) {
            return response()->json(['message' => 'You are not a member of this tenant.'], 403);
        }

        return $next($request);
    }
}
