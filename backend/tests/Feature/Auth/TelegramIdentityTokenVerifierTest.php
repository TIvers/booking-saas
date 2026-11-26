<?php

declare(strict_types=1);

use App\Services\Telegram\TelegramIdentityTokenVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function telegramOidcTestKeyPair(): array
{
    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'config' => base_path('tests/Fixtures/openssl.cnf'),
    ]);
    expect($privateKey)->not->toBeFalse();

    $details = openssl_pkey_get_details($privateKey);
    expect($details)->not->toBeFalse();

    return [$privateKey, $details['rsa']];
}

function telegramOidcTestBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function telegramOidcTestToken($privateKey, array $claims, string $keyId = 'telegram-test-key'): string
{
    $header = telegramOidcTestBase64Url(json_encode([
        'alg' => 'RS256',
        'kid' => $keyId,
    ], JSON_THROW_ON_ERROR));
    $payload = telegramOidcTestBase64Url(json_encode($claims, JSON_THROW_ON_ERROR));
    $signature = '';

    expect(openssl_sign("{$header}.{$payload}", $signature, $privateKey, OPENSSL_ALGO_SHA256))->toBeTrue();

    return "{$header}.{$payload}.".telegramOidcTestBase64Url($signature);
}

beforeEach(function (): void {
    Cache::forget('telegram_oidc_jwks');
    config()->set('services.telegram.api_base', 'https://telegram-proxy.example.test');
    config()->set('services.telegram.login_client_id', '123456789');
});

it('verifies a signed Telegram identity token and its nonce', function () {
    [$privateKey, $rsa] = telegramOidcTestKeyPair();
    Http::fake([
        'https://telegram-proxy.example.test/oauth/.well-known/jwks.json' => Http::response([
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'kid' => 'telegram-test-key',
                'n' => telegramOidcTestBase64Url($rsa['n']),
                'e' => telegramOidcTestBase64Url($rsa['e']),
            ]],
        ]),
    ]);
    $token = telegramOidcTestToken($privateKey, [
        'iss' => 'https://oauth.telegram.org',
        'aud' => '123456789',
        'sub' => '987654321',
        'nonce' => 'expected-nonce',
        'name' => 'Telegram User',
        'iat' => time(),
        'exp' => time() + 300,
    ]);

    expect(app(TelegramIdentityTokenVerifier::class)->verify($token, 'expected-nonce'))
        ->toBe([
            'sub' => '987654321',
            'name' => 'Telegram User',
        ]);
});

it('rejects Telegram identity tokens with a mismatched nonce', function () {
    [$privateKey, $rsa] = telegramOidcTestKeyPair();
    Http::fake([
        'https://telegram-proxy.example.test/oauth/.well-known/jwks.json' => Http::response([
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'kid' => 'telegram-test-key',
                'n' => telegramOidcTestBase64Url($rsa['n']),
                'e' => telegramOidcTestBase64Url($rsa['e']),
            ]],
        ]),
    ]);
    $token = telegramOidcTestToken($privateKey, [
        'iss' => 'https://oauth.telegram.org',
        'aud' => '123456789',
        'sub' => '987654321',
        'nonce' => 'different-nonce',
        'iat' => time(),
        'exp' => time() + 300,
    ]);

    expect(fn () => app(TelegramIdentityTokenVerifier::class)->verify($token, 'expected-nonce'))
        ->toThrow(ValidationException::class);
});
