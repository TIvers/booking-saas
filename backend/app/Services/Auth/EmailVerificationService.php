<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Mail\VerifyAccountEmail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailVerificationService
{
    /**
     * @return array{challenge_id: string, email: string, channel: string, expires_at: string}
     */
    public function issue(
        User $user,
        Tenant $tenant,
        string $email,
        string $purpose,
        string $channel,
    ): array {
        $id = (string) Str::uuid();
        $secret = $channel === 'code'
            ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            : Str::random(64);
        $expiresAt = now()->addMinutes(15);

        DB::transaction(function () use ($id, $user, $tenant, $email, $purpose, $channel, $secret, $expiresAt): void {
            DB::select("SELECT set_config('app.tenant_id', ?, true)", [(string) $tenant->getKey()]);
            DB::table('email_verification_challenges')
                ->where('tenant_id', $tenant->getKey())
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('email_verification_challenges')->insert([
                'id' => $id,
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'email' => $email,
                'purpose' => $purpose,
                'channel' => $channel,
                'secret_hash' => hash_hmac('sha256', $secret, (string) config('app.key')),
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $verificationUrl = url('/t/'.$tenant->slug.'/account/verify-email?challenge='
            .rawurlencode($id).'&token='.rawurlencode($secret));

        Mail::to($email)->send(new VerifyAccountEmail(
            $channel,
            $channel === 'code' ? $secret : '',
            $verificationUrl,
        ));

        return [
            'challenge_id' => $id,
            'email' => $email,
            'channel' => $channel,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function verifyCode(string $challengeId, string $code, int $tenantId): User
    {
        $result = DB::transaction(function () use ($challengeId, $code, $tenantId): ?User {
            $challenge = $this->challenge($challengeId, $tenantId);
            $this->assertUsable($challenge, 'code');
            $valid = hash_equals(
                $challenge->secret_hash,
                hash_hmac('sha256', $code, (string) config('app.key')),
            );

            if (! $valid) {
                DB::table('email_verification_challenges')
                    ->where('id', $challenge->id)
                    ->increment('attempts');

                return null;
            }

            return $this->consume($challenge);
        });

        if ($result === null) {
            $this->reject('The verification code is incorrect.');
        }

        return $result;
    }

    public function verifyLink(string $challengeId, string $token, int $tenantId): User
    {
        return DB::transaction(function () use ($challengeId, $token, $tenantId): User {
            $challenge = $this->challenge($challengeId, $tenantId);
            $this->assertUsable($challenge, 'link');

            if (! hash_equals(
                $challenge->secret_hash,
                hash_hmac('sha256', $token, (string) config('app.key')),
            )) {
                $this->reject('The verification link is invalid.');
            }

            return $this->consume($challenge);
        });
    }

    public function resend(string $challengeId, int $tenantId, string $channel): array
    {
        $challenge = DB::table('email_verification_challenges')
            ->where('id', $challengeId)
            ->where('tenant_id', $tenantId)
            ->whereNull('consumed_at')
            ->first();

        if ($challenge === null) {
            $this->reject('This email verification request is no longer available.');
        }

        $user = User::query()->findOrFail($challenge->user_id);
        $tenant = Tenant::query()->findOrFail($tenantId);

        return $this->issue(
            $user,
            $tenant,
            $challenge->email,
            $challenge->purpose,
            $channel,
        );
    }

    private function challenge(string $challengeId, int $tenantId): object
    {
        return DB::table('email_verification_challenges')
            ->where('id', $challengeId)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->first() ?? $this->reject('This email verification request is no longer available.');
    }

    private function assertUsable(object $challenge, string $channel): void
    {
        if (
            $challenge->channel !== $channel
            || $challenge->consumed_at !== null
            || now()->greaterThan($challenge->expires_at)
            || (int) $challenge->attempts >= 5
        ) {
            $this->reject('This email verification request has expired. Request a new one.');
        }
    }

    private function consume(object $challenge): User
    {
        $user = User::query()->lockForUpdate()->findOrFail($challenge->user_id);

        if ($challenge->purpose === 'registration' && $user->email !== $challenge->email) {
            $this->reject('The email address no longer matches this registration.');
        }

        $user->email = $challenge->email;
        $user->email_verified_at = now();
        $user->email_verification_pending = false;
        $user->save();
        DB::table('email_verification_challenges')
            ->where('id', $challenge->id)
            ->update(['consumed_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages([
            'email' => [$message],
        ]);
    }
}
