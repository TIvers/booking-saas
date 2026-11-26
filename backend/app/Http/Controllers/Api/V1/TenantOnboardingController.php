<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class TenantOnboardingController
{
    public function store(Request $request, EmailVerificationService $emailVerification): JsonResponse
    {
        $request->merge([
            'username' => mb_strtolower(trim((string) $request->input('username'))),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);
        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:tenant_slug_directory,slug',
            ],
            'timezone' => ['required', 'timezone'],
            'currency' => ['required', 'string', 'in:RUB,USD,EUR'],
            'owner_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s().-]{7,32}$/'],
            'username' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9_.-]{2,31}$/'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'verification_channel' => ['sometimes', 'in:code,link'],
        ]);

        try {
            [$tenant, $user] = DB::transaction(function () use ($data): array {
                $tenantId = (int) DB::selectOne(
                    "SELECT nextval(pg_get_serial_sequence('tenants', 'id')) AS id"
                )->id;
                DB::select(
                    "SELECT set_config('app.tenant_id', ?, true)",
                    [(string) $tenantId],
                );

                $now = now();
                DB::table('tenants')->insert([
                    'id' => $tenantId,
                    'name' => $data['organization_name'],
                    'slug' => $data['slug'],
                    'timezone' => $data['timezone'],
                    'currency' => $data['currency'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $tenant = Tenant::query()->findOrFail($tenantId);
                app()->instance('currentTenant', $tenant);
                DB::table('tenant_slug_directory')->insert([
                    'tenant_id' => $tenantId,
                    'slug' => $data['slug'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $userId = (int) DB::selectOne(
                    "SELECT nextval(pg_get_serial_sequence('users', 'id')) AS id"
                )->id;
                DB::table('users')->insert([
                    'id' => $userId,
                    'name' => $data['owner_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                    'account_type' => AccountType::Organization->value,
                    'username' => $data['username'],
                    'password' => Hash::make($data['password']),
                    'password_set_at' => $now,
                    'email_verification_pending' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                Membership::query()->create([
                    'user_id' => $userId,
                    'role' => Role::Owner,
                    'username' => $data['username'],
                ]);

                return [
                    $tenant,
                    User::query()->findOrFail($userId),
                ];
            });
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? (string) $exception->getCode();
            $constraint = $exception->errorInfo[2] ?? '';

            if (
                $sqlState === '23505'
                && (
                    str_contains($constraint, 'users_email')
                    || str_contains($constraint, 'email_lower_unique')
                )
            ) {
                throw ValidationException::withMessages([
                    'email' => ['The email address is already registered.'],
                ]);
            }

            if (
                $sqlState === '23505'
                && str_contains($constraint, 'username_lower_unique')
            ) {
                throw ValidationException::withMessages([
                    'username' => ['This login is already in use.'],
                ]);
            }

            if (
                $sqlState === '23505'
                && (
                    str_contains($constraint, 'tenant_slug_directory_slug')
                    || str_contains($constraint, 'tenants_slug')
                )
            ) {
                throw ValidationException::withMessages([
                    'slug' => ['This organization address is already in use.'],
                ]);
            }

            throw $exception;
        } finally {
            app()->forgetInstance('currentTenant');
        }

        $verification = $emailVerification->issue(
            $user,
            $tenant,
            $data['email'],
            'registration',
            $data['verification_channel'] ?? 'link',
        );

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => $tenant->getKey(),
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'timezone' => $tenant->timezone,
                    'currency' => $tenant->currency,
                    'demo_payments_enabled' => (bool) config('payments.demo_enabled'),
                ],
                'status' => 'verification_required',
                'verification' => $verification,
            ],
        ], Response::HTTP_ACCEPTED);
    }
}
