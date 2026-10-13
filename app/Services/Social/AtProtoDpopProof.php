<?php

namespace App\Services\Social;

use Firebase\JWT\JWT;
use RuntimeException;

final class AtProtoDpopProof
{
    /** @return array{private_key_pem: string, jwk: array{kty: string, crv: string, x: string, y: string}, thumbprint: string} */
    public function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || ! openssl_pkey_export($key, $privateKeyPem)) {
            throw new RuntimeException('Unable to create the AT Protocol DPoP key pair.');
        }
        $details = openssl_pkey_get_details($key);
        $ec = $details['ec'] ?? null;
        if (! is_array($ec) || ! is_string($ec['x'] ?? null) || ! is_string($ec['y'] ?? null)) {
            throw new RuntimeException('Unable to derive the AT Protocol DPoP public key.');
        }
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => $this->base64UrlEncode($ec['x']), 'y' => $this->base64UrlEncode($ec['y'])];
        $thumbprint = $this->base64UrlEncode(hash('sha256', json_encode([
            'crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x'], 'y' => $jwk['y'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true));

        return ['private_key_pem' => $privateKeyPem, 'jwk' => $jwk, 'thumbprint' => $thumbprint];
    }

    /** @param array{kty: string, crv: string, x: string, y: string} $jwk */
    public function create(string $privateKeyPem, array $jwk, string $method, string $url, ?string $nonce = null, ?string $accessToken = null): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null)) {
            throw new RuntimeException('DPoP proofs require an HTTPS request URL.');
        }
        $authority = strtolower($parts['host']);
        if (isset($parts['port']) && $parts['port'] !== 443) {
            $authority .= ':'.$parts['port'];
        }
        $claims = ['jti' => bin2hex(random_bytes(16)), 'htm' => strtoupper($method), 'htu' => 'https://'.$authority.($parts['path'] ?? '/'), 'iat' => time()];
        if (is_string($nonce) && $nonce !== '') {
            $claims['nonce'] = $nonce;
        }
        if (is_string($accessToken) && $accessToken !== '') {
            $claims['ath'] = $this->base64UrlEncode(hash('sha256', $accessToken, true));
        }

        return JWT::encode($claims, $privateKeyPem, 'ES256', null, ['typ' => 'dpop+jwt', 'jwk' => $jwk]);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
