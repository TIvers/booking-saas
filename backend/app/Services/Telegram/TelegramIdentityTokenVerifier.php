<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TelegramIdentityTokenVerifier
{
    /**
     * @return array{sub: string, telegram_id: string, name: ?string}
     */
    public function verify(string $token, string $expectedNonce): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || strlen($token) > 8192) {
            $this->rejectCredential();
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedClaims);
        $signature = $this->decodeBase64Url($encodedSignature);
        $clientId = config('services.telegram.login_client_id');
        $telegramId = $claims === null ? null : $this->telegramId($claims);

        if (
            $header === null
            || $claims === null
            || $signature === null
            || $telegramId === null
            || ($header['alg'] ?? null) !== 'RS256'
            || ! is_string($header['kid'] ?? null)
            || ! is_string($clientId)
            || $clientId === ''
            || ! $this->hasValidClaims($claims, $clientId, $expectedNonce)
        ) {
            $this->rejectCredential();
        }

        $key = $this->key($header['kid']);
        $verified = openssl_verify(
            "{$encodedHeader}.{$encodedClaims}",
            $signature,
            $key,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            $this->rejectCredential();
        }

        $name = $claims['name'] ?? null;

        return [
            'sub' => $claims['sub'],
            'telegram_id' => $telegramId,
            'name' => is_string($name) ? mb_substr(trim($name), 0, 255) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function hasValidClaims(array $claims, string $clientId, string $expectedNonce): bool
    {
        $audience = $claims['aud'] ?? null;
        $audienceMatches = is_string($audience)
            ? $audience === $clientId
            : is_array($audience) && in_array($clientId, $audience, true);
        $authorizedPartyMatches = isset($claims['azp'])
            ? $claims['azp'] === $clientId
            : ! is_array($audience) || count($audience) === 1;

        return ($claims['iss'] ?? null) === 'https://oauth.telegram.org'
            && is_string($claims['sub'] ?? null)
            && preg_match('/^[0-9]{1,191}$/D', $claims['sub']) === 1
            && $this->telegramId($claims) !== null
            && $audienceMatches
            && $authorizedPartyMatches
            && is_string($claims['nonce'] ?? null)
            && hash_equals($expectedNonce, $claims['nonce'])
            && is_numeric($claims['exp'] ?? null)
            && (int) $claims['exp'] > time()
            && is_numeric($claims['iat'] ?? null)
            && (int) $claims['iat'] <= time() + 60
            && (! isset($claims['name']) || is_string($claims['name']));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function telegramId(array $claims): ?string
    {
        $id = $claims['id'] ?? null;

        if (is_int($id) && $id > 0) {
            return (string) $id;
        }

        return is_string($id) && preg_match('/^[1-9][0-9]{0,19}$/D', $id) === 1
            ? $id
            : null;
    }

    private function key(string $keyId): string
    {
        $keys = $this->keys();

        if (! isset($keys[$keyId])) {
            Cache::forget('telegram_oidc_jwks');
            $keys = $this->keys();
        }

        if (! isset($keys[$keyId])) {
            $this->rejectCredential();
        }

        return $this->publicKey($keys[$keyId]);
    }

    /**
     * @return array<string, array{n: string, e: string}>
     */
    private function keys(): array
    {
        return Cache::remember('telegram_oidc_jwks', now()->addHour(), function (): array {
            $jwksUrl = rtrim((string) config('services.telegram.api_base'), '/')
                .'/oauth/.well-known/jwks.json';
            $response = Http::timeout(10)
                ->get($jwksUrl)
                ->throw();
            $payload = $response->json();

            if (! is_array($payload) || ! is_array($payload['keys'] ?? null)) {
                throw new RuntimeException('Telegram returned an invalid OpenID key set.');
            }

            $keys = [];

            foreach ($payload['keys'] as $key) {
                if (
                    ! is_array($key)
                    || ($key['kty'] ?? null) !== 'RSA'
                    || ($key['alg'] ?? 'RS256') !== 'RS256'
                    || (isset($key['use']) && $key['use'] !== 'sig')
                    || (isset($key['key_ops']) && (! is_array($key['key_ops']) || ! in_array('verify', $key['key_ops'], true)))
                    || ! is_string($key['kid'] ?? null)
                    || ! is_string($key['n'] ?? null)
                    || ! is_string($key['e'] ?? null)
                ) {
                    continue;
                }

                $modulus = $this->decodeBase64Url($key['n']);
                $exponent = $this->decodeBase64Url($key['e']);

                if ($modulus !== null && $exponent !== null) {
                    $keys[$key['kid']] = ['n' => $modulus, 'e' => $exponent];
                }
            }

            if ($keys === []) {
                throw new RuntimeException('Telegram returned no usable OpenID keys.');
            }

            return $keys;
        });
    }

    /**
     * @param  array{n: string, e: string}  $key
     */
    private function publicKey(array $key): string
    {
        $rsaKey = $this->der(0x30, $this->derInteger($key['n']).$this->derInteger($key['e']));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');

        if ($algorithm === false) {
            throw new RuntimeException('Unable to build Telegram OpenID public key.');
        }

        $subjectPublicKeyInfo = $this->der(0x30, $algorithm.$this->der(0x03, "\0".$rsaKey));
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
        $publicKey = openssl_pkey_get_public($pem);

        if ($publicKey === false) {
            throw new RuntimeException('Telegram returned an invalid OpenID public key.');
        }

        $details = openssl_pkey_get_details($publicKey);

        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new RuntimeException('Telegram returned an invalid OpenID public key.');
        }

        return $pem;
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\0");
        $value = $value === '' ? "\0" : $value;

        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\0".$value;
        }

        return $this->der(0x02, $value);
    }

    private function der(int $tag, string $value): string
    {
        $length = strlen($value);

        if ($length < 128) {
            $encodedLength = chr($length);
        } else {
            $bytes = ltrim(pack('N', $length), "\0");
            $encodedLength = chr(0x80 | strlen($bytes)).$bytes;
        }

        return chr($tag).$encodedLength.$value;
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

        $payload = json_decode($decoded, true);

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
            'id_token' => ['The Telegram sign-in credential is invalid or expired.'],
        ]);
    }
}
