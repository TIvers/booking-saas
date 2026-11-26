<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AccountType;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountMergeService
{
    public function mergeForIdentity(int $targetUserId, string $provider, string $subject): bool
    {
        return $this->withMergeAccess(function () use ($targetUserId, $provider, $subject): bool {
            $identity = DB::table('user_identities')
                ->where('provider', $provider)
                ->where('provider_subject', $subject)
                ->lockForUpdate()
                ->first();

            if ($identity === null) {
                $this->reject('The linked account changed while the merge was starting. Try again.');
            }

            $sourceUserId = (int) $identity->user_id;

            if ($sourceUserId === $targetUserId) {
                return false;
            }

            $this->mergeUsers($targetUserId, $sourceUserId);

            return true;
        });
    }

    /**
     * @return array{user_id: ?int, merged: bool, existing: bool}
     */
    public function resolveTelegramIdentity(
        string $telegramId,
        string $oidcSubject,
        ?int $targetUserId = null,
    ): array {
        return $this->withMergeAccess(function () use ($telegramId, $oidcSubject, $targetUserId): array {
            $subjects = array_values(array_unique([$telegramId, $oidcSubject]));
            $identities = DB::table('user_identities')
                ->where('provider', 'telegram')
                ->whereIn('provider_subject', $subjects)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('provider_subject');

            if ($targetUserId !== null) {
                if ($identities->isEmpty()) {
                    return ['user_id' => null, 'merged' => false, 'existing' => false];
                }

                $merged = false;

                foreach ($subjects as $subject) {
                    $identity = $this->telegramIdentity($subject);

                    if ($identity !== null && (int) $identity->user_id !== $targetUserId) {
                        $this->mergeUsers($targetUserId, (int) $identity->user_id);
                        $merged = true;
                    }
                }

                $this->normalizeTelegramIdentity($telegramId, $oidcSubject);

                return ['user_id' => $targetUserId, 'merged' => $merged, 'existing' => true];
            }

            $canonicalIdentity = $identities->get($telegramId);
            $legacyIdentity = $oidcSubject !== $telegramId
                ? $identities->get($oidcSubject)
                : null;

            if ($canonicalIdentity !== null) {
                $userId = (int) $canonicalIdentity->user_id;

                if ($legacyIdentity !== null && (int) $legacyIdentity->user_id !== $userId) {
                    $this->mergeUsers($userId, (int) $legacyIdentity->user_id);
                }

                $this->normalizeTelegramIdentity($telegramId, $oidcSubject);

                return ['user_id' => $userId, 'merged' => false, 'existing' => true];
            }

            if ($legacyIdentity !== null) {
                $userId = (int) $legacyIdentity->user_id;
                $this->normalizeTelegramIdentity($telegramId, $oidcSubject);

                return ['user_id' => $userId, 'merged' => false, 'existing' => true];
            }

            return ['user_id' => null, 'merged' => false, 'existing' => false];
        });
    }

    private function withMergeAccess(Closure $callback): mixed
    {
        $result = DB::transaction(function () use ($callback): mixed {
            DB::select("SELECT set_config('app.account_merge', 'on', true)");

            return $callback();
        });

        DB::select("SELECT set_config('app.account_merge', 'off', true)");

        return $result;
    }

    private function mergeUsers(int $targetUserId, int $sourceUserId): void
    {
        if ($targetUserId === $sourceUserId) {
            return;
        }

        $users = DB::table('users')
            ->whereIn('id', [$targetUserId, $sourceUserId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (object $user): int => (int) $user->id);
        $target = $users->get($targetUserId);
        $source = $users->get($sourceUserId);

        if ($target === null || $source === null) {
            $this->reject('One of these accounts is no longer available.');
        }

        if (
            $target->account_type !== AccountType::Customer->value
            || $source->account_type !== AccountType::Customer->value
        ) {
            $this->reject('Only client accounts can be merged.');
        }

        $sourceMemberships = DB::table('memberships')
            ->where('user_id', $sourceUserId)
            ->orderBy('tenant_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (object $membership): int => (int) $membership->tenant_id);
        $targetMemberships = DB::table('memberships')
            ->where('user_id', $targetUserId)
            ->orderBy('tenant_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (object $membership): int => (int) $membership->tenant_id);

        $this->validateMemberships($sourceMemberships, $targetMemberships);
        $this->validateUniqueTenantRecords(
            'staff',
            $sourceUserId,
            $targetUserId,
            'A specialist profile exists on both accounts in the same organization.',
        );
        $this->validateUniqueTenantRecords(
            'telegram_subscriptions',
            $sourceUserId,
            $targetUserId,
            'Telegram notifications are connected separately on both accounts in the same organization.',
        );

        $targetEmail = trim((string) ($target->email ?? ''));
        $sourceEmail = trim((string) ($source->email ?? ''));
        $targetHasPassword = $target->password_set_at !== null;
        $sourceHasPassword = $source->password_set_at !== null;

        if (
            $targetEmail !== ''
            && $sourceEmail !== ''
            && mb_strtolower($targetEmail) !== mb_strtolower($sourceEmail)
        ) {
            $this->reject('These accounts have different email addresses. Update them before merging.');
        }

        if ($targetHasPassword && $sourceHasPassword) {
            $this->reject('Both accounts have password logins. Resolve their login credentials before merging.');
        }

        if (
            $target->username !== null
            && $source->username !== null
            && $target->username !== $source->username
        ) {
            $this->reject('These accounts have different global logins. Resolve them before merging.');
        }

        $now = now();
        $targetUpdates = ['updated_at' => $now];

        if (trim((string) $target->name) === '' && trim((string) $source->name) !== '') {
            $targetUpdates['name'] = $source->name;
        }

        if (empty($target->phone) && ! empty($source->phone)) {
            $targetUpdates['phone'] = $source->phone;
        }

        if ($targetEmail === '' && $sourceEmail !== '') {
            DB::table('users')
                ->where('id', $sourceUserId)
                ->update(['email' => null, 'email_verified_at' => null]);
            $targetUpdates['email'] = $sourceEmail;
            $targetUpdates['email_verified_at'] = $source->email_verified_at;
            $targetUpdates['email_verification_pending'] = $source->email_verification_pending;
        } elseif (
            $targetEmail !== ''
            && $sourceEmail !== ''
            && $target->email_verified_at === null
            && $source->email_verified_at !== null
        ) {
            $targetUpdates['email_verified_at'] = $source->email_verified_at;
            $targetUpdates['email_verification_pending'] = false;
        }

        if (! $targetHasPassword && $sourceHasPassword) {
            $targetUpdates['password'] = $source->password;
            $targetUpdates['password_set_at'] = $source->password_set_at;
        }

        if ($target->username === null && $source->username !== null) {
            $targetUpdates['username'] = $source->username;
        }

        if (
            (! $targetHasPassword && $sourceHasPassword)
            || ($target->username === null && $source->username !== null)
        ) {
            DB::table('users')
                ->where('id', $sourceUserId)
                ->update(['username' => null]);
        }

        foreach ($sourceMemberships as $tenantId => $sourceMembership) {
            $targetMembership = $targetMemberships->get($tenantId);

            if ($targetMembership === null) {
                DB::table('memberships')
                    ->where('id', $sourceMembership->id)
                    ->update([
                        'user_id' => $targetUserId,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('memberships')
                ->where('id', $sourceMembership->id)
                ->delete();

            if ($targetMembership->username === null && $sourceMembership->username !== null) {
                DB::table('memberships')
                    ->where('id', $targetMembership->id)
                    ->update([
                        'username' => $sourceMembership->username,
                        'updated_at' => $now,
                    ]);
            }
        }

        foreach ([
            'staff' => 'user_id',
            'telegram_subscriptions' => 'user_id',
            'telegram_subscription_tokens' => 'user_id',
            'bookings' => 'customer_id',
            'booking_changes' => 'actor_user_id',
            'user_identities' => 'user_id',
        ] as $table => $column) {
            DB::table($table)
                ->where($column, $sourceUserId)
                ->update([$column => $targetUserId]);
        }

        DB::table('users')
            ->where('id', $targetUserId)
            ->update($targetUpdates);
        DB::table('users')->where('id', $sourceUserId)->delete();
    }

    private function telegramIdentity(string $subject): ?object
    {
        return DB::table('user_identities')
            ->where('provider', 'telegram')
            ->where('provider_subject', $subject)
            ->lockForUpdate()
            ->first();
    }

    private function normalizeTelegramIdentity(string $telegramId, string $oidcSubject): void
    {
        if ($telegramId === $oidcSubject) {
            return;
        }

        $canonical = $this->telegramIdentity($telegramId);
        $legacy = $this->telegramIdentity($oidcSubject);

        if ($legacy === null) {
            return;
        }

        if ($canonical !== null) {
            DB::table('user_identities')->where('id', $legacy->id)->delete();

            return;
        }

        DB::table('user_identities')
            ->where('id', $legacy->id)
            ->update([
                'provider_subject' => $telegramId,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  Collection<int, object>  $sourceMemberships
     * @param  Collection<int, object>  $targetMemberships
     */
    private function validateMemberships(Collection $sourceMemberships, Collection $targetMemberships): void
    {
        foreach ($sourceMemberships as $tenantId => $sourceMembership) {
            $targetMembership = $targetMemberships->get($tenantId);

            if ($targetMembership === null) {
                continue;
            }

            if ($sourceMembership->role !== $targetMembership->role) {
                $this->reject('The accounts have different roles in the same organization.');
            }

            if (
                $sourceMembership->username !== null
                && $targetMembership->username !== null
                && $sourceMembership->username !== $targetMembership->username
            ) {
                $this->reject('The accounts have different logins in the same organization.');
            }
        }
    }

    private function validateUniqueTenantRecords(
        string $table,
        int $sourceUserId,
        int $targetUserId,
        string $message,
    ): void {
        $rows = DB::table($table)
            ->whereIn('user_id', [$sourceUserId, $targetUserId])
            ->orderBy('tenant_id')
            ->lockForUpdate()
            ->get(['tenant_id', 'user_id']);
        $ownersByTenant = [];

        foreach ($rows as $row) {
            $tenantId = (int) $row->tenant_id;
            $userId = (int) $row->user_id;
            $ownersByTenant[$tenantId][$userId] = true;
        }

        foreach ($ownersByTenant as $owners) {
            if (isset($owners[$sourceUserId], $owners[$targetUserId])) {
                $this->reject($message);
            }
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages([
            'account' => [$message],
        ]);
    }
}
