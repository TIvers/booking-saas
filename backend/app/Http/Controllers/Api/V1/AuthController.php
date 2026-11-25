<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Telegram\TelegramWebAppAuthenticator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                'currency' => $tenant->currency,
                'demo_payments_enabled' => (bool) config('payments.demo_enabled'),
                'allow_customer_rescheduling' => $tenant->allow_customer_rescheduling,
                'max_customer_reschedules' => $tenant->max_customer_reschedules,
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant')->getKey();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-z0-9][a-z0-9_.-]{2,31}$/',
                Rule::unique('memberships', 'username')->where('tenant_id', $tenantId),
            ],
            'email' => ['nullable', 'email', 'max:255'],
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
                    'email' => $validated['email'] ?? null,
                    'password' => Hash::make($validated['password']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                Membership::create([
                    'user_id' => $userId,
                    'role' => Role::Customer,
                    'username' => $validated['username'],
                ]);

                return User::query()->findOrFail($userId);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            $constraint = $exception->errorInfo[2] ?? '';

            if (str_contains($constraint, 'memberships_tenant_username')) {
                return response()->json([
                    'message' => 'The username is already in use in this organization.',
                    'errors' => ['username' => ['The username is already in use in this organization.']],
                ], 422);
            }

            if (str_contains($constraint, 'users_email')) {
                return response()->json([
                    'message' => 'The email address is already registered.',
                    'errors' => ['email' => ['The email address is already registered.']],
                ], 422);
            }

            throw $exception;
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => $this->userPayload($user, $tenant)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $membership = Membership::query()
            ->with('user')
            ->where('username', $credentials['login'])
            ->first();

        if ($membership === null && filter_var($credentials['login'], FILTER_VALIDATE_EMAIL)) {
            $membership = Membership::query()
                ->with('user')
                ->whereHas('user', fn ($query) => $query->where('email', $credentials['login']))
                ->first();

            if ($membership?->username !== null) {
                $membership = null;
            }
        }

        if (
            $membership === null
            || $membership->user === null
            || ! Hash::check($credentials['password'], $membership->user->getAuthPassword())
            || (
                $membership->role === Role::Staff
                && ! Staff::query()
                    ->where('user_id', $membership->user_id)
                    ->where('is_active', true)
                    ->exists()
            )
        ) {
            return response()->json(['message' => 'The provided credentials are invalid.'], 422);
        }

        $user = $membership->user;
        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $this->userPayload($user, $tenant, $membership)]);
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
        abort_if(
            $membership->role === Role::Staff
            && ! Staff::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('is_active', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
        );

        return response()->json(['data' => $this->userPayload($user, $tenant, $membership)]);
    }

    public function updateUsername(Request $request): JsonResponse
    {
        $membership = $this->membership($request);

        abort_unless(
            in_array($membership->role, [Role::Customer, Role::Staff], true),
            Response::HTTP_FORBIDDEN,
        );

        $tenantId = (int) $request->attributes->get('tenant')->getKey();
        $data = $request->validate([
            'username' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-z0-9][a-z0-9_.-]{2,31}$/',
                Rule::unique('memberships', 'username')
                    ->where('tenant_id', $tenantId)
                    ->ignore($membership->getKey()),
            ],
        ]);

        $membership->username = $data['username'];
        $membership->save();

        return response()->json(['data' => ['username' => $membership->username]]);
    }

    public function updateEmail(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
        ]);

        try {
            $user->email = $data['email'];
            $user->email_verified_at = null;
            $user->save();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => ['The email address is already registered.'],
            ]);
        }

        return response()->json(['data' => ['email' => $user->email]]);
    }

    public function telegram(Request $request, TelegramWebAppAuthenticator $authenticator): JsonResponse
    {
        $data = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
            'bot' => ['required', Rule::in(['main', 'tenant'])],
        ]);
        $telegram = $authenticator->verify($data['init_data'], $data['bot']);
        $this->allowTelegramIdentityLookup();
        $identity = UserIdentity::query()
            ->where('provider', 'telegram')
            ->where('provider_subject', (string) $telegram->user['id'])
            ->first();

        if ($identity === null) {
            return response()->json([
                'data' => [
                    'status' => 'unlinked',
                    'name' => $this->telegramName($telegram->user),
                ],
            ]);
        }

        $membership = DB::transaction(function () use ($identity): Membership {
            $membership = Membership::query()
                ->where('user_id', $identity->user_id)
                ->first();

            if ($membership === null) {
                return Membership::query()->create([
                    'user_id' => $identity->user_id,
                    'role' => Role::Customer,
                ]);
            }

            return $membership;
        });

        abort_unless(
            in_array($membership->role, [Role::Customer, Role::Staff], true),
            Response::HTTP_FORBIDDEN,
            'Telegram sign-in is available only for customers and specialists.',
        );

        $user = User::query()->findOrFail($identity->user_id);
        abort_if(
            $membership->role === Role::Staff
            && ! Staff::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('is_active', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
        );
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $this->userPayload($user, $tenant, $membership)]);
    }

    public function registerTelegram(
        Request $request,
        TelegramWebAppAuthenticator $authenticator,
    ): JsonResponse {
        $data = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
            'bot' => ['required', Rule::in(['main', 'tenant'])],
        ]);
        $telegram = $authenticator->verify($data['init_data'], $data['bot']);
        $this->allowTelegramIdentityLookup();

        if (
            UserIdentity::query()
                ->where('provider', 'telegram')
                ->where('provider_subject', (string) $telegram->user['id'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'telegram' => ['This Telegram account is already linked. Sign in with Telegram instead.'],
            ]);
        }

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        try {
            $user = DB::transaction(function () use ($telegram): User {
                $userId = (int) DB::selectOne(
                    "SELECT nextval(pg_get_serial_sequence('users', 'id')) AS id"
                )->id;
                $now = now();

                DB::table('users')->insert([
                    'id' => $userId,
                    'name' => $this->telegramName($telegram->user),
                    'email' => null,
                    'password' => Hash::make(Str::random(64)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                Membership::query()->create([
                    'user_id' => $userId,
                    'role' => Role::Customer,
                ]);
                UserIdentity::query()->create([
                    'user_id' => $userId,
                    'provider' => 'telegram',
                    'provider_subject' => (string) $telegram->user['id'],
                    'email' => null,
                    'email_verified_at' => null,
                ]);

                return User::query()->findOrFail($userId);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'telegram' => ['This Telegram account was registered in another request. Sign in with Telegram instead.'],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => $this->userPayload($user, $tenant)], 201);
    }

    public function linkTelegram(
        Request $request,
        TelegramWebAppAuthenticator $authenticator,
    ): JsonResponse {
        $membership = $this->membership($request);
        abort_unless(
            in_array($membership->role, [Role::Customer, Role::Staff], true),
            Response::HTTP_FORBIDDEN,
        );
        abort_if(
            $membership->role === Role::Staff
            && ! Staff::query()
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->where('is_active', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
        );

        $data = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
            'bot' => ['required', Rule::in(['main', 'tenant'])],
        ]);
        $telegram = $authenticator->verify($data['init_data'], $data['bot']);
        $this->allowTelegramIdentityLookup();
        $existing = UserIdentity::query()
            ->where('provider', 'telegram')
            ->where('provider_subject', (string) $telegram->user['id'])
            ->first();

        if ($existing !== null && (int) $existing->user_id !== (int) $request->user()->getAuthIdentifier()) {
            throw ValidationException::withMessages([
                'telegram' => ['This Telegram account is linked to another site account.'],
            ]);
        }

        try {
            UserIdentity::query()->firstOrCreate(
                [
                    'provider' => 'telegram',
                    'provider_subject' => (string) $telegram->user['id'],
                ],
                [
                    'user_id' => $request->user()->getAuthIdentifier(),
                    'email' => null,
                    'email_verified_at' => null,
                ],
            );
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'telegram' => ['This Telegram account is linked to another site account.'],
            ]);
        }

        return response()->json(['data' => ['linked' => true]]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function userPayload(
        User $user,
        Tenant $tenant,
        ?Membership $membership = null,
    ): array {
        $this->allowTelegramIdentityLookup();
        $membership ??= Membership::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->firstOrFail();

        return [
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'username' => $membership->username,
                'telegram_linked' => $user->identities()
                    ->where('provider', 'telegram')
                    ->exists(),
            ],
            'tenant' => [
                'id' => $tenant->getKey(),
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
            ],
            'role' => $membership->role->value,
        ];
    }

    private function membership(Request $request): Membership
    {
        return Membership::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    private function allowTelegramIdentityLookup(): void
    {
        DB::select("SELECT set_config('app.telegram_identity_lookup', 'on', true)");
    }

    /**
     * @param  array{id: int, first_name: string, last_name?: string}  $telegramUser
     */
    private function telegramName(array $telegramUser): string
    {
        return trim($telegramUser['first_name'].' '.($telegramUser['last_name'] ?? ''));
    }
}
