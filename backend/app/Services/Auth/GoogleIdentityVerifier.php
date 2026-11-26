<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class GoogleIdentityVerifier
{
    /**
     * @return array{sub: string, email: string, name: ?string}
     */
    public function verify(string $credential): array
    {
        $parts = explode('.', $credential);

        if (count($parts) !== 3 || strlen($credential) > 8192) {
            $this->rejectCredential();
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedClaims);
        $signature = $this->decodeBase64Url($encodedSignature);
        $clientId = config('services.google.client_id');

        if (
            $header === null
            || $claims === null
            || $signature === null
            || ($header['alg'] ?? null) !== 'RS256'
            || ! is_string($header['kid'] ?? null)
            || ! is_string($clientId)
            || $clientId === ''
            || ! $this->hasValidClaims($claims, $clientId)
        ) {
            $this->rejectCredential();
        }

        $certificate = $this->certificate($header['kid']);
        $verified = openssl_verify(
            "{$encodedHeader}.{$encodedClaims}",
            $signature,
            $certificate,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            $this->rejectCredential();
        }

        return [
            'sub' => $claims['sub'],
            'email' => mb_strtolower($claims['email']),
            'name' => is_string($claims['name'] ?? null) ? trim($claims['name']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function hasValidClaims(array $claims, string $clientId): bool
    {
        $audience = $claims['aud'] ?? null;
        $audienceMatches = is_string($audience)
            ? $audience === $clientId
            : is_array($audience) && in_array($clientId, $audience, true);
        $authorizedPartyMatches = isset($claims['azp'])
            ? $claims['azp'] === $clientId
            : ! is_array($audience) || count($audience) === 1;
        $emailVerified = ($claims['email_verified'] ?? false) === true
            || ($claims['email_verified'] ?? null) === 'true';
        $now = time();
        $notBeforeValid = ! array_key_exists('nbf', $claims)
            || (is_numeric($claims['nbf']) && (int) $claims['nbf'] <= $now + 60);

        return in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && is_string($claims['sub'] ?? null)
            && $claims['sub'] !== ''
            && strlen($claims['sub']) <= 191
            && is_string($claims['email'] ?? null)
            && strlen($claims['email']) <= 255
            && filter_var($claims['email'], FILTER_VALIDATE_EMAIL) !== false
            && $emailVerified
            && $audienceMatches
            && $authorizedPartyMatches
            && is_numeric($claims['exp'] ?? null)
            && (int) $claims['exp'] > $now
            && is_numeric($claims['iat'] ?? null)
            && (int) $claims['iat'] <= $now + 60
            && $notBeforeValid;
    }

    private function certificate(string $keyId): string
    {
        $certificates = $this->certificates();

        if (! isset($certificates[$keyId])) {
            Cache::forget('google_oidc_certificates');
            $certificates = $this->certificates();
        }

        if (! isset($certificates[$keyId])) {
            $this->rejectCredential();
        }

        return $certificates[$keyId];
    }

    /**
     * @return array<string, string>
     */
    private function certificates(): array
    {
        return Cache::remember('google_oidc_certificates', now()->addHour(), function (): array {
            $response = Http::timeout(5)
                ->get('https://www.googleapis.com/oauth2/v1/certs')
                ->throw();

            $keys = $response->json();

            if (! is_array($keys)) {
                throw new RuntimeException('Google returned an invalid OpenID certificate set.');
            }

            $certificates = [];

            foreach ($keys as $keyId => $certificate) {
                if (! is_string($keyId) || ! is_string($certificate) || ! str_contains($certificate, 'BEGIN CERTIFICATE')) {
                    continue;
                }

                $certificates[$keyId] = $certificate;
            }

            if ($certificates === []) {
                throw new RuntimeException('Google returned no usable OpenID certificates.');
            }

            return $certificates;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $value): ?array
    {
        $decoded = $this->decodeBase64Url($value);

        if ($decoded === null) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function decodeBase64Url(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            return null;
        }

        $base64 = strtr($value, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);

        return $decoded === false ? null : $decoded;
    }

    private function rejectCredential(): never
    {
        throw ValidationException::withMessages([
            'credential' => ['The Google sign-in credential is invalid or expired.'],
        ]);
    }
}
