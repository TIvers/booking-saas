<?php

declare(strict_types=1);

use App\Services\Auth\GoogleIdentityVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function googleTestKeyPair(): array
{
    $privateKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'config' => base_path('tests/Fixtures/openssl.cnf'),
    ]);
    expect($privateKey)->not->toBeFalse();

    $request = openssl_csr_new(
        ['commonName' => 'Google test key'],
        $privateKey,
        ['config' => base_path('tests/Fixtures/openssl.cnf')],
    );
    expect($request)->not->toBeFalse();

    $certificate = openssl_csr_sign(
        $request,
        null,
        $privateKey,
        1,
        ['config' => base_path('tests/Fixtures/openssl.cnf')],
    );
    expect($certificate)->not->toBeFalse();

    $certificatePem = '';
    expect(openssl_x509_export($certificate, $certificatePem))->toBeTrue();

    return [$privateKey, $certificatePem];
}

function googleTestToken($privateKey, array $claims, string $keyId = 'google-test-key'): string
{
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $header = $encode(json_encode(['alg' => 'RS256', 'kid' => $keyId], JSON_THROW_ON_ERROR));
    $payload = $encode(json_encode($claims, JSON_THROW_ON_ERROR));
    $signature = '';

    expect(openssl_sign("{$header}.{$payload}", $signature, $privateKey, OPENSSL_ALGO_SHA256))->toBeTrue();

    return "{$header}.{$payload}.".$encode($signature);
}

beforeEach(function () {
    Cache::forget('google_oidc_certificates');
    config()->set('services.google.client_id', 'google-client-id');
});

it('verifies Google identity token signature and trusted claims', function () {
    [$privateKey, $certificate] = googleTestKeyPair();
    Http::fake([
        'https://www.googleapis.com/oauth2/v1/certs' => Http::response([
            'google-test-key' => $certificate,
        ]),
    ]);
    $token = googleTestToken($privateKey, [
        'iss' => 'https://accounts.google.com',
        'aud' => 'google-client-id',
        'sub' => 'google-subject',
        'email' => 'User@example.test',
        'email_verified' => true,
        'name' => 'Google User',
        'iat' => time(),
        'exp' => time() + 300,
    ]);

    expect(app(GoogleIdentityVerifier::class)->verify($token))
        ->toBe([
            'sub' => 'google-subject',
            'email' => 'user@example.test',
            'name' => 'Google User',
        ]);
});

it('rejects invalid signatures and unverified Google email claims', function () {
    [$privateKey, $certificate] = googleTestKeyPair();
    Http::fake([
        'https://www.googleapis.com/oauth2/v1/certs' => Http::response([
            'google-test-key' => $certificate,
        ]),
    ]);
    $claims = [
        'iss' => 'accounts.google.com',
        'aud' => 'google-client-id',
        'sub' => 'google-subject',
        'email' => 'user@example.test',
        'email_verified' => false,
        'iat' => time(),
        'exp' => time() + 300,
    ];
    $unverifiedToken = googleTestToken($privateKey, $claims);

    expect(fn () => app(GoogleIdentityVerifier::class)->verify($unverifiedToken))
        ->toThrow(ValidationException::class);

    $claims['email_verified'] = true;
    $validToken = googleTestToken($privateKey, $claims);
    $parts = explode('.', $validToken);
    $parts[1] = rtrim(strtr(base64_encode(json_encode(
        [...$claims, 'email' => 'attacker@example.test'],
        JSON_THROW_ON_ERROR,
    )), '+/', '-_'), '=');

    expect(fn () => app(GoogleIdentityVerifier::class)->verify(implode('.', $parts)))
        ->toThrow(ValidationException::class);
});
