<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GitHubAppTokenService
{
    public function installationToken(IntegrationConnection $connection): string
    {
        $installationId = $connection->github_installation_id;
        $metadata = $connection->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        if ($connection->provider !== 'github' || ! $connection->isOperational() || ! is_numeric($installationId)
            || ($metadata['installation_suspended_at'] ?? null) !== null || ($metadata['installation_deleted_at'] ?? null) !== null) {
            throw new RuntimeException('An active GitHub App installation is required.');
        }

        $cacheKey = 'github-app-installation-token:'.hash('sha256', (string) $installationId);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return Cache::lock($cacheKey.':lock', 15)->block(10, function () use ($connection, $installationId, $cacheKey): string {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $appId = trim((string) config('services.github.app_id'));
            $privateKey = str_replace(chr(92).'n', "\n", (string) config('services.github.private_key'));
            if ($appId === '' || ! ctype_digit($appId) || $privateKey === '') {
                throw new RuntimeException('GitHub App credentials are not configured.');
            }

            $jwt = $this->appJwt($appId, $privateKey);
            $response = Http::baseUrl('https://api.github.com')->withToken($jwt)->acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'CR8OR'])
                ->timeout((int) config('services.github.timeout', 15))
                ->post('/app/installations/'.$installationId.'/access_tokens', [
                    'permissions' => ['issues' => 'read', 'metadata' => 'read'],
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('GitHub App installation token request failed with HTTP '.$response->status().'.');
            }

            $token = $response->json('token');
            $expiresAt = $response->json('expires_at');
            $permissions = $response->json('permissions');
            if (! is_string($token) || $token === '' || ! is_string($expiresAt)
                || ! is_array($permissions) || ($permissions['issues'] ?? null) !== 'read' || ($permissions['metadata'] ?? null) !== 'read') {
                throw new RuntimeException('GitHub returned an invalid installation token response.');
            }

            $metadata = $connection->getAttribute('metadata');
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['github_permissions'] = $permissions;
            $connection->forceFill(['metadata' => $metadata])->save();

            $expiresAtTimestamp = (int) \Illuminate\Support\Carbon::parse($expiresAt)->timestamp;
            $ttl = $expiresAtTimestamp - (int) now()->timestamp - 300;
            if ($ttl <= 0) {
                throw new RuntimeException('GitHub returned an installation token too close to expiry.');
            }
            Cache::put($cacheKey, $token, now()->addSeconds($ttl));

            return $token;
        });
    }

    private function appJwt(string $appId, string $privateKey): string
    {
        $now = (int) now()->timestamp;
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $appId], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$payload;
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('GitHub App private key is invalid or unavailable.');
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
