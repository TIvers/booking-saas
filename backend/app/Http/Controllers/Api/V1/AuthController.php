<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Auth\AccountMergeService;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\GoogleIdentityVerifier;
use App\Services\Telegram\TelegramIdentityTokenVerifier;
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

    private function loginVerifiedEmailUser(Request $request, User $user): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $membership = Membership::query()
            ->where('user_id', $user->getKey())
            ->firstOrFail();
        abort_if(
            $membership->role === Role::Staff
            && ! Staff::query()
                ->where('user_id', $user->getKey())
                ->where('is_active', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
        );

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => $this->userPayload($user, $tenant, $membership)]);
    }

    public function register(Request $request, EmailVerificationService $emailVerification): JsonResponse
    {
        $request->merge([
            'username' => mb_strtolower(trim((string) $request->input('username'))),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-z0-9][a-z0-9_.-]{2,31}$/',
                Rule::unique('users', 'username')
                    ->where('account_type', AccountType::Customer->value),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->where('account_type', AccountType::Customer->value),
            ],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'verification_channel' => ['sometimes', Rule::in(['code', 'link'])],
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
                    'account_type' => AccountType::Customer->value,
                    'password' => Hash::make($validated['password']),
                    'username' => $validated['username'],
                    'password_set_at' => $now,
                    'email_verification_pending' => true,
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

            if (
                str_contains($constraint, 'username_lower_unique')
            ) {
                return response()->json([
                    'message' => 'This login is already in use.',
                    'errors' => ['username' => ['This login is already in use.']],
                ], 422);
            }

            if (
                str_contains($constraint, 'users_email')
                || str_contains($constraint, 'email_lower_unique')
            ) {
                return response()->json([
                    'message' => 'The email address is already registered.',
                    'errors' => ['email' => ['The email address is already registered.']],
                ], 422);
            }

            throw $exception;
        }

        $verification = $emailVerification->issue(
            $user,
            $tenant,
            $validated['email'],
            'registration',
            $validated['verification_channel'] ?? 'link',
        );

        return response()->json([
            'data' => [
                'status' => 'verification_required',
                'verification' => $verification,
            ],
        ], Response::HTTP_ACCEPTED);
    }

    public function login(Request $request, EmailVerificationService $emailVerification): JsonResponse
    {
        return $this->loginForAccountType($request, $emailVerification, AccountType::Customer);
    }

    public function loginOrganization(
        Request $request,
        EmailVerificationService $emailVerification,
    ): JsonResponse {
        return $this->loginForAccountType($request, $emailVerification, AccountType::Organization);
    }

    private function loginForAccountType(
        Request $request,
        EmailVerificationService $emailVerification,
        AccountType $accountType,
    ): JsonResponse {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'verification_channel' => ['sometimes', Rule::in(['code', 'link'])],
        ]);

        $login = trim($credentials['login']);
        $membership = null;
        $isEmailLogin = filter_var($login, FILTER_VALIDATE_EMAIL) !== false;

        if ($isEmailLogin) {
            $membership = Membership::query()
                ->with('user')
                ->whereHas(
                    'user',
                    fn ($query) => $query
                        ->where('account_type', $accountType->value)
                        ->whereRaw('LOWER(email) = ?', [mb_strtolower($login)])
                )
                ->first();
        }

        if ($membership === null) {
            $membership = Membership::query()
                ->with('user')
                ->whereHas(
                    'user',
                    fn ($query) => $query
                        ->where('account_type', $accountType->value)
                        ->whereRaw('LOWER(username) = ?', [mb_strtolower($login)])
                )
                ->first();
        }

        if ($membership === null) {
            $membership = Membership::query()
                ->with('user')
                ->whereRaw('LOWER(username) = ?', [mb_strtolower($login)])
                ->whereHas(
                    'user',
                    fn ($query) => $query->where('account_type', $accountType->value)
                )
                ->first();
        }

        $roleAllowed = $membership !== null && (
            $accountType === AccountType::Customer
                ? $membership->role === Role::Customer
                : in_array($membership->role, [Role::Owner, Role::Manager, Role::Staff], true)
        );

        if (
            $membership === null
            || $membership->user === null
            || ! $roleAllowed
            || ! Hash::check($credentials['password'], $membership->user->getAuthPassword())
        ) {
            return response()->json(['message' => 'The provided credentials are invalid.'], 422);
        }

        $user = $membership->user;
        if ($user->email_verification_pending) {
            /** @var Tenant $tenant */
            $tenant = $request->attributes->get('tenant');
            $verification = $emailVerification->issue(
                $user,
                $tenant,
                (string) $user->email,
                'registration',
                $credentials['verification_channel'] ?? 'link',
            );

            return response()->json([
                'data' => [
                    'status' => 'verification_required',
                    'verification' => $verification,
                ],
            ], Response::HTTP_ACCEPTED);
        }

        if (
            ($isEmailLogin && $user->email_verified_at === null)
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

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $this->userPayload($user, $tenant, $membership)]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();
        $user = User::query()->findOrFail($authenticatedUser->getAuthIdentifier());

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
        /** @var User $user */
        $user = $request->user();

        $request->merge(['username' => mb_strtolower(trim((string) $request->input('username')))]);
        DB::select("SELECT set_config('app.google_email_lookup', 'on', true)");
        $data = $request->validate([
            'username' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-z0-9][a-z0-9_.-]{2,31}$/',
                Rule::unique('users', 'username')
                    ->where('account_type', $user->account_type->value)
                    ->ignore($user->getKey()),
            ],
        ]);

        $user->username = $data['username'];

        try {
            $user->save();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'username' => ['This login is already in use.'],
            ]);
        }

        return response()->json(['data' => ['username' => $user->username]]);
    }

    public function updateEmail(Request $request, EmailVerificationService $emailVerification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'verification_channel' => ['sometimes', Rule::in(['code', 'link'])],
        ]);

        if ($user->email === $data['email'] && $user->email_verified_at !== null) {
            return response()->json([
                'data' => [
                    'email' => $user->email,
                    'email_verified' => true,
                    'verification_required' => false,
                ],
            ]);
        }

        DB::select("SELECT set_config('app.google_email_lookup', 'on', true)");
        $emailOwner = User::query()
            ->where('account_type', $user->account_type->value)
            ->whereRaw('LOWER(email) = ?', [$data['email']])
            ->where('id', '<>', $user->getKey())
            ->exists();

        if ($emailOwner) {
            throw ValidationException::withMessages([
                'email' => ['The email address is already registered.'],
            ]);
        }

        $verification = $emailVerification->issue(
            $user,
            $tenant,
            $data['email'],
            'email_update',
            $data['verification_channel'] ?? 'link',
        );

        return response()->json([
            'data' => [
                'email' => $data['email'],
                'email_verified' => false,
                'verification_required' => true,
                'verification' => $verification,
            ],
        ], Response::HTTP_ACCEPTED);
    }

    public function setPassword(Request $request): JsonResponse
    {
        /** @var User $authenticatedUser */
        $authenticatedUser = $request->user();
        $user = User::query()->findOrFail($authenticatedUser->getAuthIdentifier());
        $data = $request->validate([
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($user->email === null || $user->email_verified_at === null) {
            throw ValidationException::withMessages([
                'email' => ['Verify an email address before setting a password.'],
            ]);
        }

        $user->password = $data['password'];
        $user->password_set_at = now();
        $user->save();
        Auth::guard('web')->setUser($user);

        return response()->json(['data' => ['has_password' => true]]);
    }

    public function verifyEmailCode(
        Request $request,
        EmailVerificationService $emailVerification,
    ): JsonResponse {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
        ]);
        $tenantId = (int) $request->attributes->get('tenant')->getKey();
        $user = $emailVerification->verifyCode($data['challenge_id'], $data['code'], $tenantId);

        return $this->loginVerifiedEmailUser($request, $user);
    }

    public function verifyEmailLink(
        Request $request,
        EmailVerificationService $emailVerification,
    ): JsonResponse {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'token' => ['required', 'string', 'max:128'],
        ]);
        $tenantId = (int) $request->attributes->get('tenant')->getKey();
        $user = $emailVerification->verifyLink($data['challenge_id'], $data['token'], $tenantId);

        return $this->loginVerifiedEmailUser($request, $user);
    }

    public function resendEmailVerification(
        Request $request,
        EmailVerificationService $emailVerification,
    ): JsonResponse {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'verification_channel' => ['required', Rule::in(['code', 'link'])],
        ]);
        $tenantId = (int) $request->attributes->get('tenant')->getKey();

        return response()->json([
            'data' => $emailVerification->resend(
                $data['challenge_id'],
                $tenantId,
                $data['verification_channel'],
            ),
        ], Response::HTTP_ACCEPTED);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s().-]{7,32}$/'],
        ]);

        $user->name = trim($data['name']);
        $user->phone = isset($data['phone']) ? trim($data['phone']) : null;
        $user->save();

        return response()->json([
            'data' => [
                'name' => $user->name,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function googleConfig(): JsonResponse
    {
        $clientId = config('services.google.client_id');

        return response()->json([
            'data' => [
                'enabled' => is_string($clientId) && $clientId !== '',
                'client_id' => is_string($clientId) && $clientId !== '' ? $clientId : null,
            ],
        ]);
    }

    public function google(Request $request, GoogleIdentityVerifier $verifier): JsonResponse
    {
        $clientId = config('services.google.client_id');

        if (! is_string($clientId) || $clientId === '') {
            return response()->json(['message' => 'Google sign-in is not configured.'], 503);
        }

        $data = $request->validate([
            'credential' => ['required', 'string', 'max:8192'],
        ]);
        $google = $verifier->verify($data['credential']);
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        [$user, $membership] = DB::transaction(function () use ($google): array {
            DB::select("SELECT set_config('app.google_identity_lookup', 'on', true)");
            DB::select("SELECT set_config('app.google_email_lookup', 'on', true)");

            $identity = UserIdentity::query()
                ->where('provider', 'google')
                ->where('provider_subject', $google['sub'])
                ->first();
            $userId = $identity?->user_id;

            if (
                $userId !== null
                && DB::table('users')->where('id', $userId)->value('account_type')
                    !== AccountType::Customer->value
            ) {
                throw ValidationException::withMessages([
                    'credential' => ['Google sign-in is only available to client accounts.'],
                ]);
            }

            if ($userId === null) {
                $existingUser = User::query()
                    ->where('account_type', AccountType::Customer->value)
                    ->whereRaw('LOWER(email) = ?', [$google['email']])
                    ->first(['id']);
                $userId = $existingUser?->getKey();
            }

            if ($userId === null) {
                $userId = (int) DB::selectOne(
                    "SELECT nextval(pg_get_serial_sequence('users', 'id')) AS id"
                )->id;
                $name = trim($google['name'] ?? '');
                $name = $name !== '' ? $name : explode('@', $google['email'], 2)[0];

                DB::table('users')->insert([
                    'id' => $userId,
                    'name' => mb_substr($name, 0, 255),
                    'email' => $google['email'],
                    'account_type' => AccountType::Customer->value,
                    'password' => Hash::make(Str::random(64)),
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                User::query()
                    ->whereKey($userId)
                    ->whereRaw('LOWER(email) = ?', [$google['email']])
                    ->whereNull('email_verified_at')
                    ->update([
                        'email_verified_at' => now(),
                        'email_verification_pending' => false,
                    ]);
            }

            $membership = Membership::query()
                ->where('user_id', $userId)
                ->first();

            if ($membership === null) {
                $membership = Membership::query()->create([
                    'user_id' => $userId,
                    'role' => Role::Customer,
                ]);
            }

            if ($identity === null) {
                UserIdentity::query()->create([
                    'user_id' => $userId,
                    'provider' => 'google',
                    'provider_subject' => $google['sub'],
                    'email' => $google['email'],
                    'email_verified_at' => now(),
                ]);
            }

            return [User::query()->findOrFail($userId), $membership];
        });

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        return response()->json([
            'data' => $this->userPayload($user, $tenant, $membership),
        ]);
    }

    public function linkGoogle(
        Request $request,
        GoogleIdentityVerifier $verifier,
        AccountMergeService $accountMergeService,
    ): JsonResponse {
        abort_unless(
            $request->user()->account_type === AccountType::Customer,
            Response::HTTP_FORBIDDEN,
            'Google can only be linked to a client account.',
        );

        $data = $request->validate([
            'credential' => ['required', 'string', 'max:8192'],
        ]);
        $google = $verifier->verify($data['credential']);
        $userId = (int) $request->user()->getAuthIdentifier();

        try {
            $linkResult = DB::transaction(function () use ($google, $userId, $accountMergeService): array {
                $this->allowGoogleIdentityLookup();
                DB::select("SELECT set_config('app.google_email_lookup', 'on', true)");
                $existing = UserIdentity::query()
                    ->where('provider', 'google')
                    ->where('provider_subject', $google['sub'])
                    ->first();

                $merged = $existing !== null && (int) $existing->user_id !== $userId
                    ? $accountMergeService->mergeForIdentity($userId, 'google', $google['sub'])
                    : false;

                if ($existing === null) {
                    UserIdentity::query()->firstOrCreate(
                        [
                            'provider' => 'google',
                            'provider_subject' => $google['sub'],
                        ],
                        [
                            'user_id' => $userId,
                            'email' => $google['email'],
                            'email_verified_at' => now(),
                        ],
                    );
                }

                $current = User::query()->findOrFail($userId);
                if ($current->email === null) {
                    $emailOwner = User::query()
                        ->where('account_type', AccountType::Customer->value)
                        ->whereRaw('LOWER(email) = ?', [$google['email']])
                        ->where('id', '<>', $userId)
                        ->exists();

                    if (! $emailOwner) {
                        DB::table('users')
                            ->where('id', $userId)
                            ->update([
                                'email' => $google['email'],
                                'email_verified_at' => now(),
                                'email_verification_pending' => false,
                                'updated_at' => now(),
                            ]);
                    }
                } elseif (
                    mb_strtolower($current->email) === mb_strtolower($google['email'])
                    && $current->email_verified_at === null
                ) {
                    DB::table('users')
                        ->where('id', $userId)
                        ->update([
                            'email_verified_at' => now(),
                            'email_verification_pending' => false,
                            'updated_at' => now(),
                        ]);
                }

                return [
                    'merged' => $merged,
                    'already_linked' => $existing !== null && (int) $existing->user_id === $userId,
                ];
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'google' => ['This Google account is linked to another site account.'],
            ]);
        }

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $user = User::query()->findOrFail($userId);
        $membership = $this->membership($request);
        $payload = $this->userPayload($user, $tenant, $membership);
        $payload['linked'] = true;
        $payload['merged'] = $linkResult['merged'];
        $payload['already_linked'] = $linkResult['already_linked'];

        return response()->json(['data' => $payload]);
    }

    public function telegramOidcConfig(Request $request): JsonResponse
    {
        $clientId = config('services.telegram.login_client_id');
        $enabled = is_string($clientId) && $clientId !== '';

        if ($enabled) {
            $request->session()->forget('telegram_oidc_pending');
            $request->session()->put('telegram_oidc_nonce', bin2hex(random_bytes(32)));
        }

        return response()->json([
            'data' => [
                'enabled' => $enabled,
                'client_id' => $enabled ? $clientId : null,
                'nonce' => $enabled ? $request->session()->get('telegram_oidc_nonce') : null,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function telegramOidc(
        Request $request,
        TelegramIdentityTokenVerifier $verifier,
        AccountMergeService $accountMergeService,
    ): JsonResponse {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
        ]);
        $nonce = $request->session()->pull('telegram_oidc_nonce');

        if (! is_string($nonce) || $nonce === '') {
            throw ValidationException::withMessages([
                'id_token' => ['The Telegram sign-in session expired. Try again.'],
            ]);
        }

        $telegram = $verifier->verify($data['id_token'], $nonce);
        $identity = $accountMergeService->resolveTelegramIdentity(
            $telegram['telegram_id'],
            $telegram['sub'],
        );

        if ($identity['user_id'] === null) {
            $request->session()->put('telegram_oidc_pending', $telegram);

            return response()->json([
                'data' => [
                    'status' => 'unlinked',
                    'name' => $telegram['name'] ?: 'Пользователь Telegram',
                ],
            ]);
        }

        $request->session()->forget('telegram_oidc_pending');
        $userId = $identity['user_id'];
        $user = User::query()->findOrFail($userId);
        abort_unless(
            $user->account_type === AccountType::Customer,
            Response::HTTP_FORBIDDEN,
            'Telegram sign-in is only available to client accounts.',
        );
        $membership = DB::transaction(function () use ($userId): Membership {
            return Membership::query()
                ->where('user_id', $userId)
                ->first() ?? Membership::query()->create([
                    'user_id' => $userId,
                    'role' => Role::Customer,
                ]);
        });

        abort_unless(
            $membership->role === Role::Customer,
            Response::HTTP_FORBIDDEN,
            'Telegram sign-in is only available to client accounts.',
        );

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'data' => $this->userPayload($user, $tenant, $membership),
        ]);
    }

    public function registerTelegramOidc(
        Request $request,
        AccountMergeService $accountMergeService,
    ): JsonResponse {
        $telegram = $this->pendingTelegramOidcIdentity($request);
        $identity = $accountMergeService->resolveTelegramIdentity(
            $telegram['telegram_id'],
            $telegram['sub'],
        );

        if ($identity['user_id'] !== null) {
            throw ValidationException::withMessages([
                'telegram' => ['This Telegram account is already linked. Sign in with Telegram instead.'],
            ]);
        }

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        try {
            [$user, $membership] = DB::transaction(function () use ($telegram): array {
                $userId = (int) DB::selectOne(
                    "SELECT nextval(pg_get_serial_sequence('users', 'id')) AS id"
                )->id;
                $now = now();

                DB::table('users')->insert([
                    'id' => $userId,
                    'name' => $telegram['name'] ?: 'Пользователь Telegram',
                    'email' => null,
                    'account_type' => AccountType::Customer->value,
                    'password' => Hash::make(Str::random(64)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $membership = Membership::query()->create([
                    'user_id' => $userId,
                    'role' => Role::Customer,
                ]);
                UserIdentity::query()->create([
                    'user_id' => $userId,
                    'provider' => 'telegram',
                    'provider_subject' => $telegram['telegram_id'],
                    'email' => null,
                    'email_verified_at' => null,
                ]);

                return [User::query()->findOrFail($userId), $membership];
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'telegram' => ['This Telegram account was registered in another request. Sign in with Telegram instead.'],
            ]);
        }

        $request->session()->forget('telegram_oidc_pending');
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'data' => $this->userPayload($user, $tenant, $membership),
        ], 201);
    }

    public function linkTelegramOidc(
        Request $request,
        TelegramIdentityTokenVerifier $verifier,
        AccountMergeService $accountMergeService,
    ): JsonResponse {
        $membership = $this->membership($request);
        abort_unless(
            $request->user()->account_type === AccountType::Customer
                && $membership->role === Role::Customer,
            Response::HTTP_FORBIDDEN,
        );

        if ($request->exists('id_token')) {
            $data = $request->validate([
                'id_token' => ['required', 'string', 'max:8192'],
            ]);
            $nonce = $request->session()->pull('telegram_oidc_nonce');

            if (! is_string($nonce) || $nonce === '') {
                throw ValidationException::withMessages([
                    'id_token' => ['The Telegram sign-in session expired. Try again.'],
                ]);
            }

            $request->session()->put(
                'telegram_oidc_pending',
                $verifier->verify($data['id_token'], $nonce),
            );
        }

        $telegram = $this->pendingTelegramOidcIdentity($request);
        try {
            $linkResult = DB::transaction(function () use ($telegram, $request, $accountMergeService): array {
                $userId = (int) $request->user()->getAuthIdentifier();
                $identity = $accountMergeService->resolveTelegramIdentity(
                    $telegram['telegram_id'],
                    $telegram['sub'],
                    $userId,
                );

                if ($identity['user_id'] === null) {
                    UserIdentity::query()->firstOrCreate(
                        [
                            'provider' => 'telegram',
                            'provider_subject' => $telegram['telegram_id'],
                        ],
                        [
                            'user_id' => $userId,
                            'email' => null,
                            'email_verified_at' => null,
                        ],
                    );
                }

                return [
                    'merged' => $identity['merged'],
                    'already_linked' => $identity['existing'] && ! $identity['merged'],
                ];
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'telegram' => ['This Telegram account is linked to another site account.'],
            ]);
        }

        $request->session()->forget('telegram_oidc_pending');

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $user = User::query()->findOrFail($request->user()->getAuthIdentifier());
        $membership = $this->membership($request);
        $payload = $this->userPayload($user, $tenant, $membership);
        $payload['linked'] = true;
        $payload['merged'] = $linkResult['merged'];
        $payload['already_linked'] = $linkResult['already_linked'];

        return response()->json(['data' => $payload]);
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

        $user = User::query()->findOrFail($identity->user_id);
        abort_unless(
            $user->account_type === AccountType::Customer,
            Response::HTTP_FORBIDDEN,
            'Telegram sign-in is only available to client accounts.',
        );
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
            $membership->role === Role::Customer,
            Response::HTTP_FORBIDDEN,
            'Telegram sign-in is only available to client accounts.',
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
                    'account_type' => AccountType::Customer->value,
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
        AccountMergeService $accountMergeService,
    ): JsonResponse {
        $membership = $this->membership($request);
        abort_unless(
            $request->user()->account_type === AccountType::Customer
                && $membership->role === Role::Customer,
            Response::HTTP_FORBIDDEN,
        );

        $data = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
            'bot' => ['required', Rule::in(['main', 'tenant'])],
        ]);
        $telegram = $authenticator->verify($data['init_data'], $data['bot']);
        try {
            $linkResult = DB::transaction(function () use ($telegram, $request, $accountMergeService): array {
                $this->allowTelegramIdentityLookup();
                $userId = (int) $request->user()->getAuthIdentifier();
                $subject = (string) $telegram->user['id'];
                $existing = UserIdentity::query()
                    ->where('provider', 'telegram')
                    ->where('provider_subject', $subject)
                    ->first();
                $merged = $existing !== null && (int) $existing->user_id !== $userId
                    ? $accountMergeService->mergeForIdentity($userId, 'telegram', $subject)
                    : false;

                if ($existing === null) {
                    UserIdentity::query()->firstOrCreate(
                        [
                            'provider' => 'telegram',
                            'provider_subject' => $subject,
                        ],
                        [
                            'user_id' => $userId,
                            'email' => null,
                            'email_verified_at' => null,
                        ],
                    );
                }

                return [
                    'merged' => $merged,
                    'already_linked' => $existing !== null && (int) $existing->user_id === $userId,
                ];
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'telegram' => ['This Telegram account is linked to another site account.'],
            ]);
        }

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $user = User::query()->findOrFail($request->user()->getAuthIdentifier());
        $membership = $this->membership($request);
        $payload = $this->userPayload($user, $tenant, $membership);
        $payload['linked'] = true;
        $payload['merged'] = $linkResult['merged'];
        $payload['already_linked'] = $linkResult['already_linked'];

        return response()->json(['data' => $payload]);
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
                'account_type' => $user->account_type->value,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
                'phone' => $user->phone,
                'username' => $user->username ?? $membership->username,
                'has_password' => $user->password_set_at !== null,
                'telegram_linked' => $user->identities()
                    ->where('provider', 'telegram')
                    ->exists(),
                'google_linked' => $user->identities()
                    ->where('provider', 'google')
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

    private function allowGoogleIdentityLookup(): void
    {
        DB::select("SELECT set_config('app.google_identity_lookup', 'on', true)");
    }

    /**
     * @return array{sub: string, telegram_id: string, name: ?string}
     */
    private function pendingTelegramOidcIdentity(Request $request): array
    {
        $telegram = $request->session()->get('telegram_oidc_pending');

        if (
            ! is_array($telegram)
            || ! is_string($telegram['sub'] ?? null)
            || ! is_string($telegram['telegram_id'] ?? null)
            || (isset($telegram['name']) && ! is_string($telegram['name']))
        ) {
            throw ValidationException::withMessages([
                'telegram' => ['Telegram sign-in has expired. Start again.'],
            ]);
        }

        return [
            'sub' => $telegram['sub'],
            'telegram_id' => $telegram['telegram_id'],
            'name' => is_string($telegram['name'] ?? null) ? $telegram['name'] : null,
        ];
    }

    /**
     * @param  array{id: int, first_name: string, last_name?: string}  $telegramUser
     */
    private function telegramName(array $telegramUser): string
    {
        return trim($telegramUser['first_name'].' '.($telegramUser['last_name'] ?? ''));
    }
}
