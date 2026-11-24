<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController
{
    public function tenant(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'data' => [
                'id' => $tenant->getKey(),
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        try {
            $user = DB::transaction(function () use ($validated): User {
                $userId = (int) DB::selectOne(
                    "SELECT nextval(pg_get_serial_sequence('users', 'id')) AS id"
                )->id;
                $now = now();

                // RLS hides a user until its membership exists, so this insert must not RETURN the row yet.
                DB::table('users')->insert([
                    'id' => $userId,
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                Membership::create([
                    'user_id' => $userId,
                    'role' => Role::Customer,
                ]);

                return User::query()->findOrFail($userId);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            return response()->json([
                'message' => 'The email address is already registered.',
                'errors' => ['email' => ['The email address is already registered.']],
            ], 422);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => $this->userPayload($user, $tenant)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            return response()->json(['message' => 'The provided credentials are invalid.'], 422);
        }

        $user = Auth::guard('web')->user();

        if ($user === null || ! Membership::query()->where('user_id', $user->getAuthIdentifier())->exists()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'The provided credentials are invalid.'], 422);
        }

        $request->session()->regenerate();

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $this->userPayload($user, $tenant)]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $membership = Membership::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->firstOrFail();

        return response()->json(['data' => $this->userPayload($user, $tenant, $membership->role)]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function userPayload(User $user, Tenant $tenant, Role|string|null $role = null): array
    {
        $role ??= Membership::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->value('role');

        return [
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'tenant' => [
                'id' => $tenant->getKey(),
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
            ],
            'role' => $role instanceof Role ? $role->value : $role,
        ];
    }
}
