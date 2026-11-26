<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->publicStaffQuery()->orderBy('display_name')->get(),
        ]);
    }

    public function manageIndex(): JsonResponse
    {
        $staff = Staff::query()
            ->with([
                'location:id,name,timezone',
                'services' => fn (BelongsToMany $query) => $query->orderBy('name'),
            ])
            ->orderBy('display_name')
            ->get();
        $userIds = $staff->pluck('user_id')->filter()->values();
        $usernames = $userIds->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', $userIds)
                ->pluck('username', 'id');
        $legacyUsernames = $userIds->isEmpty()
            ? collect()
            : Membership::query()
                ->whereIn('user_id', $userIds)
                ->pluck('username', 'user_id');

        $staff->each(function (Staff $member) use ($usernames): void {
            $member->setAttribute('account_linked', $member->user_id !== null);
            $member->setAttribute(
                'account_username',
                $member->user_id === null
                    ? null
                    : ($usernames->get($member->user_id) ?? $legacyUsernames->get($member->user_id)),
            );
        });

        return response()->json([
            'data' => $staff,
        ]);
    }

    public function show(Staff $staff): JsonResponse
    {
        abort_unless($staff->is_active, 404);

        return response()->json([
            'data' => $this->loadPublicRelations($staff),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);
        $serviceIds = $data['service_ids'] ?? [];
        unset($data['service_ids']);
        $data['is_active'] ??= true;

        $staff = DB::transaction(function () use ($data, $serviceIds): Staff {
            $staff = Staff::query()->create($data);
            $staff->services()->sync($serviceIds);

            return $staff;
        });

        return response()->json([
            'data' => $this->loadPublicRelations($staff),
        ], 201);
    }

    public function update(Request $request, Staff $staff): JsonResponse
    {
        $data = $this->validatedData($request, updating: true);
        $serviceIds = $data['service_ids'] ?? null;
        unset($data['service_ids']);

        DB::transaction(function () use ($data, $serviceIds, $staff): void {
            $staff->update($data);

            if ($serviceIds !== null) {
                $staff->services()->sync($serviceIds);
            }
        });

        return response()->json([
            'data' => $this->loadPublicRelations($staff->refresh()),
        ]);
    }

    public function destroy(Staff $staff): JsonResponse
    {
        $staff->update(['is_active' => false]);

        return response()->json(['data' => $staff->refresh()]);
    }

    public function createAccount(Request $request, Staff $staff): JsonResponse
    {
        $membership = Membership::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
        abort_unless($membership->role === Role::Owner, 403);
        abort_if($staff->user_id !== null, 409, 'This specialist already has an account.');

        $tenantId = (int) $request->attributes->get('tenant')->getKey();
        $request->merge([
            'username' => mb_strtolower(trim((string) $request->input('username'))),
            'email' => $request->input('email') === null
                ? null
                : mb_strtolower(trim((string) $request->input('email'))),
        ]);
        $data = $request->validate([
            'username' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-z0-9][a-z0-9_.-]{2,31}$/',
                Rule::unique('users', 'username')
                    ->where('account_type', AccountType::Organization->value),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->where('account_type', AccountType::Organization->value),
            ],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        try {
            DB::transaction(function () use ($data, $staff): void {
                $userId = (int) DB::selectOne(
                    "SELECT nextval(pg_get_serial_sequence('users', 'id')) AS id"
                )->id;
                $now = now();

                DB::table('users')->insert([
                    'id' => $userId,
                    'name' => $staff->display_name,
                    'account_type' => AccountType::Organization->value,
                    'username' => $data['username'],
                    'email' => $data['email'] ?? null,
                    'password' => Hash::make($data['password']),
                    'password_set_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                Membership::query()->create([
                    'user_id' => $userId,
                    'role' => Role::Staff,
                    'username' => $data['username'],
                ]);
                $staff->update(['user_id' => $userId]);
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

            throw $exception;
        }

        return response()->json([
            'data' => [
                'staff_id' => $staff->getKey(),
                'account_linked' => true,
                'account_username' => $data['username'],
            ],
        ], 201);
    }

    private function publicStaffQuery(): Builder
    {
        return Staff::query()
            ->where('is_active', true)
            ->with([
                'location:id,name,timezone',
                'services' => fn (BelongsToMany $query) => $query
                    ->where('is_active', true)
                    ->orderBy('name'),
            ]);
    }

    private function loadPublicRelations(Staff $staff): Staff
    {
        return $staff->load([
            'location:id,name,timezone',
            'services' => fn (BelongsToMany $query) => $query
                ->where('is_active', true)
                ->orderBy('name'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $tenantId = (int) $request->attributes->get('tenant')->getKey();

        return $request->validate([
            'location_id' => [
                $required,
                'integer',
                Rule::exists('locations', 'id')->where('tenant_id', $tenantId),
            ],
            'display_name' => [$required, 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'service_ids' => ['sometimes', 'array', 'max:100'],
            'service_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('services', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
        ]);
    }
}
