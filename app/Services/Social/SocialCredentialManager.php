<?php

namespace App\Services\Social;

use App\Data\Social\SocialTokenSet;
use App\Enums\SocialRevocationResult;
use App\Exceptions\SocialAuthorizationException;
use App\Models\IntegrationConnection;
use App\Models\SocialAuthorizationAudit;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SocialCredentialManager
{
    public function __construct(
        private readonly SocialCredentialStore $store,
        private readonly SocialAuthorizationProviderRegistry $providers,
    ) {}

    public function resolveAccessToken(IntegrationConnection $connection): string
    {
        if (! $connection->isOperational()) {
            throw new SocialAuthorizationException('connection_not_operational', 'The social connection is not active.');
        }

        $credential = $this->store->forConnection($connection);
        if (! $this->requiresRefresh($credential->tokenSet())) {
            return $credential->tokenSet()->accessToken;
        }

        $lockKey = 'social-credential-refresh:'.hash('sha256', $credential->reference);

        try {
            return Cache::lock($lockKey, (int) config('social_authorization.refresh_lock_seconds', 30))
                ->block((int) config('social_authorization.refresh_lock_wait_seconds', 10), function () use ($connection): string {
                    $connection->refresh();
                    if (! $connection->isOperational()) {
                        throw new SocialAuthorizationException('connection_not_operational', 'The social connection is not active.');
                    }

                    $credential = $this->store->forConnection($connection);
                    $tokens = $credential->tokenSet();
                    if (! $this->requiresRefresh($tokens)) {
                        return $tokens->accessToken;
                    }

                    $provider = $this->providers->forProvider($connection->provider);

                    try {
                        $refreshed = $provider->refresh($tokens);
                    } catch (SocialAuthorizationException $exception) {
                        if (! $exception->retryable && in_array($exception->errorCode, [
                            'refresh_token_missing',
                            'invalid_grant',
                            'invalid_token',
                            'refresh_unsupported',
                        ], true)) {
                            $this->requireReconnect($connection, $exception->errorCode);
                        }

                        throw $exception;
                    }

                    $this->store->update($credential, $refreshed);

                    return $refreshed->accessToken;
                });
        } catch (LockTimeoutException) {
            throw new SocialAuthorizationException('refresh_in_progress', 'The social credential is being refreshed by another request.', true);
        }
    }

    public function disconnect(IntegrationConnection $connection, int $actorId): SocialRevocationResult
    {
        if ($connection->status === IntegrationConnection::STATUS_REVOKED) {
            throw new SocialAuthorizationException('connection_already_revoked', 'The social connection is already revoked.');
        }

        if ($connection->status !== IntegrationConnection::STATUS_DISABLED) {
            $connection->transitionTo(IntegrationConnection::STATUS_DISABLED)->save();
        }

        $result = SocialRevocationResult::Failed;
        $failureCode = null;
        $credential = null;

        try {
            $credential = $this->store->forConnection($connection);
            $provider = $this->providers->forProvider($connection->provider);
            $result = $provider->revoke($credential->tokenSet());
        } catch (SocialAuthorizationException $exception) {
            $failureCode = $exception->errorCode;
            if (in_array($exception->errorCode, ['provider_not_configured', 'provider_protocol_unsupported', 'provider_unsupported'], true)) {
                $result = SocialRevocationResult::Unsupported;
            }
        } catch (Throwable) {
            $failureCode = 'remote_revocation_failed';
            $result = SocialRevocationResult::Failed;
        }

        if ($result === SocialRevocationResult::Revoked) {
            $connection->refresh();
            $connection->transitionTo(IntegrationConnection::STATUS_REVOKED)->save();
            if ($credential !== null) {
                $this->store->deleteReference($credential->reference, (int) $connection->organization_id, $connection->provider);
            }
        }

        SocialAuthorizationAudit::query()->create([
            'organization_id' => $connection->organization_id,
            'enterprise_id' => $connection->enterprise_id,
            'actor_id' => $actorId,
            'integration_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'action' => 'connection_disconnected',
            'correlation_id' => (string) str()->uuid(),
            'metadata' => array_filter([
                'local_state' => $connection->status,
                'remote_revocation' => $result->value,
                'failure_code' => $failureCode,
            ], fn ($value): bool => $value !== null),
        ]);

        return $result;
    }

    private function requiresRefresh(SocialTokenSet $tokens): bool
    {
        if ($tokens->accessTokenExpiresAt === null) {
            return false;
        }

        return $tokens->accessTokenExpiresAt->lessThanOrEqualTo(
            now()->addSeconds((int) config('social_authorization.access_token_refresh_leeway_seconds', 300)),
        );
    }

    private function requireReconnect(IntegrationConnection $connection, string $failureCode): void
    {
        $connection->refresh();

        if (in_array($connection->status, [
            IntegrationConnection::STATUS_ACTIVE,
            IntegrationConnection::STATUS_DISABLED,
            IntegrationConnection::STATUS_DEGRADED,
        ], true)) {
            $connection->transitionTo(IntegrationConnection::STATUS_RECONNECT_REQUIRED)->save();
        }

        SocialAuthorizationAudit::query()->create([
            'organization_id' => $connection->organization_id,
            'enterprise_id' => $connection->enterprise_id,
            'integration_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'action' => 'credential_refresh_failed',
            'correlation_id' => (string) str()->uuid(),
            'metadata' => ['failure_code' => $failureCode, 'local_state' => $connection->status],
        ]);
    }
}