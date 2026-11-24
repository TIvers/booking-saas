<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Staff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membership = $user === null
            ? null
            : Membership::query()->where('user_id', $user->getAuthIdentifier())->first();

        if (
            $membership === null
            || $membership->role !== Role::Staff
            || ! Staff::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('is_active', true)
                ->exists()
        ) {
            return response()->json(['message' => 'You are not a linked specialist in this organization.'], 403);
        }

        return $next($request);
    }
}
