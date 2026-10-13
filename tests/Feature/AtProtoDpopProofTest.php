<?php

use App\Services\Social\AtProtoDpopProof;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

it('creates a verifiable P-256 DPoP proof bound to the normalized request URL and access token', function () {
    $proofs = app(AtProtoDpopProof::class);
    $keyPair = $proofs->generateKeyPair();
    $token = 'sample-access-token';
    $proof = $proofs->create($keyPair['private_key_pem'], $keyPair['jwk'], 'get', 'https://pds.example.test/xrpc/com.atproto.repo.getRecord?repo=did%3Aplc%3Aabc', 'nonce-123', $token);
    $privateKey = openssl_pkey_get_private($keyPair['private_key_pem']);
    $details = openssl_pkey_get_details($privateKey);
    $claims = JWT::decode($proof, new Key($details['key'], 'ES256'));
    $header = json_decode(base64_decode(strtr(explode('.', $proof)[0], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);

    expect($keyPair['jwk']['kty'])->toBe('EC')
        ->and($keyPair['jwk']['crv'])->toBe('P-256')
        ->and($keyPair['thumbprint'])->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and($header['typ'])->toBe('dpop+jwt')
        ->and($header['jwk'])->toBe($keyPair['jwk'])
        ->and($claims->htm)->toBe('GET')
        ->and($claims->htu)->toBe('https://pds.example.test/xrpc/com.atproto.repo.getRecord')
        ->and($claims->nonce)->toBe('nonce-123')
        ->and($claims->ath)->toBe(rtrim(strtr(base64_encode(hash('sha256', $token, true)), '+/', '-_'), '='))
        ->and($claims->jti)->toBeString()
        ->and($claims->iat)->toBeInt();
});

it('does not create DPoP proofs for non-HTTPS request URLs', function () {
    $proofs = app(AtProtoDpopProof::class);
    $keyPair = $proofs->generateKeyPair();

    expect(fn () => $proofs->create($keyPair['private_key_pem'], $keyPair['jwk'], 'GET', 'http://localhost/xrpc/test'))
        ->toThrow(RuntimeException::class, 'HTTPS');
});
