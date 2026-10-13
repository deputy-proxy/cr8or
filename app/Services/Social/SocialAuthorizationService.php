<?php

namespace App\Services\Social;

use App\Contracts\StatefulSocialAuthorizationProvider;
use App\Data\Social\SocialTokenSet;
use App\Enums\SocialRevocationResult;
use App\Exceptions\SocialAuthorizationException;
use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialAuthorizationAttempt;
use App\Models\SocialAuthorizationAudit;
use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final class SocialAuthorizationService
{
    public function __construct(
        private readonly SocialAuthorizationProviderRegistry $providers,
        private readonly SocialCredentialStore $credentials,
        private readonly SocialCredentialManager $credentialManager,
    ) {}

    /**
     * @param  list<string>  $requestedScopes
     */
    public function start(
        User $actor,
        int $organizationId,
        string $destinationNetwork,
        ?int $enterpriseId = null,
        ?int $channelId = null,
        array $requestedScopes = [],
        ?string $accountIdentifier = null,
    ): string {
        $organization = Organization::query()->findOrFail($organizationId);
        Gate::forUser($actor)->authorize('update', $organization);

        $providerKey = $this->providers->providerForNetwork($destinationNetwork);
        $provider = $this->providers->forProvider($providerKey);
        $providerConfiguration = $this->providers->configuration($providerKey);
        if (! in_array($destinationNetwork, $this->providers->destinationNetworks($providerKey), true)) {
            throw new SocialAuthorizationException('destination_network_unsupported', 'This provider cannot authorize the requested destination network.');
        }

        if ($channelId !== null) {
            $channel = Channel::query()->with('enterprise')->findOrFail($channelId);
            if ((int) $channel->enterprise->organization_id !== (int) $organization->id) {
                throw new AuthorizationException('The channel does not belong to the selected organization.');
            }
            if ($enterpriseId !== null && (int) $channel->enterprise_id !== $enterpriseId) {
                throw new AuthorizationException('The channel does not belong to the selected enterprise.');
            }
            $enterpriseId = (int) $channel->enterprise_id;
        }

        if ($enterpriseId !== null) {
            $enterprise = Enterprise::query()->where('organization_id', $organization->id)->findOrFail($enterpriseId);
            Gate::forUser($actor)->authorize('update', $enterprise);
        }

        $allowedScopes = $providerConfiguration['scopes'] ?? [];
        $scopes = $requestedScopes === [] ? $allowedScopes : array_values(array_unique($requestedScopes));
        if (! is_array($allowedScopes) || $scopes === [] || array_diff($scopes, $allowedScopes) !== []) {
            throw new SocialAuthorizationException('scope_not_allowed', 'The requested authorization scopes are not allowed for this provider.');
        }

        $redirectUri = $providerConfiguration['redirect_uri'] ?? null;
        if (! is_string($redirectUri) || trim($redirectUri) === '') {
            throw new SocialAuthorizationException('provider_not_configured', 'The social authorization callback URI is not configured.');
        }

        $state = $this->base64UrlEncode(random_bytes(32));
        $verifier = $this->base64UrlEncode(random_bytes(32));
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));
        $attempt = SocialAuthorizationAttempt::query()->create([
            'user_id' => $actor->id,
            'organization_id' => $organization->id,
            'enterprise_id' => $enterpriseId,
            'channel_id' => $channelId,
            'provider' => $providerKey,
            'destination_network' => $destinationNetwork,
            'state_hash' => hash('sha256', $state),
            'code_verifier_encrypted' => Crypt::encryptString($verifier),
            'redirect_uri' => $redirectUri,
            'requested_scopes' => $scopes,
            'status' => SocialAuthorizationAttempt::STATUS_PENDING,
            'expires_at' => now()->addMinutes((int) config('social_authorization.attempt_ttl_minutes', 10)),
            'correlation_id' => (string) Str::uuid(),
        ]);

        try {
            if ($provider instanceof StatefulSocialAuthorizationProvider) {
                $initiation = $provider->initiate($state, $redirectUri, $scopes, $verifier, $accountIdentifier);
                $attempt->setProviderContext(Crypt::encryptString(json_encode($initiation->context, JSON_THROW_ON_ERROR)));
                $url = $initiation->url;
            } else {
                $url = $provider->authorizationUrl(
                    $state,
                    $redirectUri,
                    $scopes,
                    ($providerConfiguration['pkce'] ?? false) === true ? $challenge : null,
                );
            }
        } catch (Throwable $exception) {
            $attempt->transitionTo(SocialAuthorizationAttempt::STATUS_FAILED);
            $attempt->failure_code = $exception instanceof SocialAuthorizationException ? $exception->errorCode : 'provider_configuration_error';
            $attempt->save();
            $this->audit($attempt, 'authorization_start_failed', ['failure_code' => $attempt->failure_code]);

            if ($exception instanceof SocialAuthorizationException) {
                throw $exception;
            }

            throw new SocialAuthorizationException('provider_configuration_error', 'The social authorization request could not be created.');
        }

        $this->audit($attempt, 'authorization_started', ['scope_count' => count($scopes)]);

        return $url;
    }

    /** @param array<string, mixed> $callbackParameters */
    public function complete(
        User $actor,
        string $callbackProvider,
        string $state,
        ?string $code,
        ?string $providerError = null,
        array $callbackParameters = [],
    ): SocialAuthorizationAttempt {
        if ($state === '' || strlen($state) > 256) {
            throw new SocialAuthorizationException('state_invalid', 'The social authorization state is invalid or expired.');
        }

        $stateHash = hash('sha256', $state);
        $attempt = SocialAuthorizationAttempt::query()->where('state_hash', $stateHash)->first();
        if ($attempt === null) {
            throw new SocialAuthorizationException('state_invalid', 'The social authorization state is invalid or expired.');
        }

        if ($attempt->provider !== $callbackProvider || (int) $attempt->user_id !== (int) $actor->id) {
            $this->audit($attempt, 'authorization_callback_rejected', ['failure_code' => 'actor_or_provider_mismatch'], $actor->id);
            throw new SocialAuthorizationException('state_context_mismatch', 'The authorization callback does not match the initiating user and provider.');
        }

        $this->authorizeAttemptContext($actor, $attempt);

        $claim = DB::transaction(function () use ($attempt, $stateHash): array {
            $locked = SocialAuthorizationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SocialAuthorizationAttempt::STATUS_PENDING) {
                throw new SocialAuthorizationException('state_replayed', 'The social authorization state has already been used.');
            }

            if ($locked->expires_at->isPast()) {
                $locked->transitionTo(SocialAuthorizationAttempt::STATUS_EXPIRED);
                $locked->failure_code = 'state_expired';
                $locked->save();

                return ['attempt' => $locked, 'expired' => true];
            }

            if (! hash_equals($locked->state_hash, $stateHash)) {
                throw new SocialAuthorizationException('state_invalid', 'The social authorization state is invalid or expired.');
            }

            $locked->transitionTo(SocialAuthorizationAttempt::STATUS_PROCESSING);
            $locked->consumed_at = now();
            $locked->save();

            return ['attempt' => $locked, 'expired' => false];
        });

        /** @var SocialAuthorizationAttempt $attempt */
        $attempt = $claim['attempt'];
        if ($claim['expired']) {
            $this->audit($attempt, 'authorization_expired', ['failure_code' => 'state_expired']);
            throw new SocialAuthorizationException('state_expired', 'The social authorization state is invalid or expired.');
        }

        if (is_string($providerError) && $providerError !== '') {
            $attempt->transitionTo(SocialAuthorizationAttempt::STATUS_DENIED);
            $attempt->failure_code = $this->safeFailureCode($providerError);
            $attempt->save();
            $this->audit($attempt, 'authorization_denied', ['failure_code' => $attempt->failure_code]);

            return $attempt->refresh();
        }

        if (! is_string($code) || trim($code) === '') {
            $this->failAttempt($attempt, 'authorization_code_missing');
            throw new SocialAuthorizationException('authorization_code_missing', 'The provider did not complete the authorization request.');
        }

        $tokens = null;
        $provider = null;

        try {
            $provider = $this->providers->forProvider($attempt->provider);
            $verifier = Crypt::decryptString($attempt->code_verifier_encrypted);
            if ($provider instanceof StatefulSocialAuthorizationProvider) {
                if (! is_string($attempt->provider_context_encrypted) || $attempt->provider_context_encrypted === '') {
                    throw new SocialAuthorizationException('authorization_context_missing', 'The provider authorization context is missing or expired.');
                }
                $context = json_decode(Crypt::decryptString($attempt->provider_context_encrypted), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($context)) {
                    throw new SocialAuthorizationException('authorization_context_invalid', 'The provider authorization context is invalid or expired.');
                }
                $tokens = $provider->exchangeWithContext($code, $attempt->redirect_uri, $verifier, $context, $callbackParameters);
            } else {
                $tokens = $provider->exchangeAuthorizationCode($code, $attempt->redirect_uri, $verifier);
            }
            $discovery = $provider->discover($tokens, $attempt->destination_network);
            $destinationCredentialTokens = [];
            foreach ($discovery->destinations as $destination) {
                if (is_string($destination->credentialToken) && $destination->credentialToken !== '') {
                    $destinationCredentialTokens[$destination->provider.':'.$destination->externalId] = [
                        'access_token' => $destination->credentialToken,
                        'expires_at' => $destination->credentialExpiresAt,
                    ];
                }
            }
            $tokens = $tokens->withDestinationTokens($destinationCredentialTokens);

            $oldCredentialReference = null;
            $newCredentialReference = null;
            DB::transaction(function () use ($actor, $attempt, $tokens, $discovery, &$oldCredentialReference, &$newCredentialReference): void {
                $organization = Organization::query()->findOrFail($attempt->organization_id);
                $identity = SocialIdentity::query()
                    ->where('organization_id', $organization->id)
                    ->where('provider', $attempt->provider)
                    ->where('external_id', $discovery->identityExternalId)
                    ->first();

                if ($identity === null) {
                    $identity = SocialIdentity::query()->create([
                        'organization_id' => $organization->id,
                        'provider' => $attempt->provider,
                        'external_id' => $discovery->identityExternalId,
                        'name' => $discovery->identityName,
                    ]);
                } elseif ($identity->name !== $discovery->identityName) {
                    $identity->name = $discovery->identityName;
                    $identity->save();
                }

                $newCredentialReference = $this->credentials->store($organization, $attempt->provider, $tokens);
                $connection = IntegrationConnection::query()->where('social_identity_id', $identity->id)->first();

                if ($connection === null) {
                    $connection = IntegrationConnection::query()->create([
                        'organization_id' => $organization->id,
                        'enterprise_id' => null,
                        'social_identity_id' => $identity->id,
                        'provider' => $attempt->provider,
                        'external_account_id' => $identity->external_id,
                        'credential_reference' => $newCredentialReference,
                        'status' => IntegrationConnection::STATUS_ACTIVE,
                    ]);
                } else {
                    $oldCredentialReference = $connection->credential_reference;
                    if (in_array($connection->status, [
                        IntegrationConnection::STATUS_EXPIRED,
                        IntegrationConnection::STATUS_RECONNECT_REQUIRED,
                        IntegrationConnection::STATUS_REVOKED,
                    ], true)) {
                        $connection->reauthorize($newCredentialReference);
                    } else {
                        $connection->rotateCredentialReference($newCredentialReference);
                        if ($connection->status !== IntegrationConnection::STATUS_ACTIVE) {
                            $connection->transitionTo(IntegrationConnection::STATUS_ACTIVE);
                        }
                    }

                    $connection->save();
                }

                $attempt->social_identity_id = $identity->id;
                $attempt->integration_connection_id = $connection->id;
                $attempt->discovered_destinations = array_map(
                    fn ($destination): array => [
                        'provider' => $destination->provider,
                        'external_id' => $destination->externalId,
                        'name' => $destination->name,
                        'url' => $destination->url,
                    ],
                    $discovery->destinations,
                );
                $attempt->completed_at = now();
                $attempt->transitionTo(SocialAuthorizationAttempt::STATUS_COMPLETED);
                $attempt->save();

                $this->audit($attempt, 'authorization_completed', [
                    'destination_count' => count($discovery->destinations),
                    'identity_id' => $identity->id,
                    'connection_id' => $connection->id,
                ], $actor->id, $connection->id);
            });

            if (is_string($oldCredentialReference) && $oldCredentialReference !== $newCredentialReference && str_starts_with($oldCredentialReference, 'social:')) {
                $this->credentials->deleteReference($oldCredentialReference, (int) $attempt->organization_id, $attempt->provider);
            }

            return $attempt->refresh();
        } catch (Throwable $exception) {
            if ($tokens instanceof SocialTokenSet && $provider !== null) {
                try {
                    $provider->revoke($tokens);
                } catch (Throwable) {
                    // The authorization attempt still fails closed; token material is never logged.
                }
            }

            $this->failAttempt($attempt, $exception instanceof SocialAuthorizationException ? $exception->errorCode : 'authorization_processing_failed');

            if ($exception instanceof SocialAuthorizationException) {
                throw $exception;
            }

            throw new SocialAuthorizationException('authorization_processing_failed', 'The social authorization could not be completed.');
        }
    }

    /** @param list<int|string> $selectedIndexes */
    public function attachDestinations(User $actor, SocialAuthorizationAttempt $attempt, int $channelId, array $selectedIndexes): int
    {
        $this->authorizeAttemptContext($actor, $attempt);

        if ($attempt->status !== SocialAuthorizationAttempt::STATUS_COMPLETED || $attempt->destinations_attached_at !== null) {
            throw new SocialAuthorizationException('destinations_unavailable', 'The discovered destinations are no longer available for selection.');
        }

        $channel = Channel::query()->with('enterprise')->findOrFail($channelId);
        if ((int) $channel->enterprise->organization_id !== (int) $attempt->organization_id) {
            throw new AuthorizationException('The destination channel does not belong to the authorization organization.');
        }
        if ($attempt->enterprise_id !== null && (int) $channel->enterprise_id !== (int) $attempt->enterprise_id) {
            throw new AuthorizationException('The destination channel does not belong to the authorization enterprise.');
        }
        Gate::forUser($actor)->authorize('update', $channel->enterprise);

        if ($attempt->channel_id !== null && (int) $attempt->channel_id !== (int) $channel->id) {
            throw new AuthorizationException('The destination channel differs from the channel selected when authorization started.');
        }

        $discovered = $attempt->discovered_destinations ?? [];
        foreach ($selectedIndexes as $selectedIndex) {
            if (is_string($selectedIndex) && ! ctype_digit($selectedIndex)) {
                throw new SocialAuthorizationException('destination_selection_invalid', 'Select at least one destination returned by the provider.');
            }
        }
        $indexes = array_values(array_unique(array_map('intval', $selectedIndexes)));
        if ($indexes === [] || array_filter($indexes, fn (int $index): bool => $index < 0 || ! array_key_exists($index, $discovered)) !== []) {
            throw new SocialAuthorizationException('destination_selection_invalid', 'Select at least one destination returned by the provider.');
        }

        $identity = SocialIdentity::query()->findOrFail($attempt->social_identity_id);
        $connection = IntegrationConnection::query()->findOrFail($attempt->integration_connection_id);
        if (! $connection->isOperational()) {
            throw new SocialAuthorizationException('connection_not_operational', 'The social connection is not active.');
        }

        $attached = DB::transaction(function () use ($actor, $attempt, $channel, $identity, $discovered, $indexes): int {
            $count = 0;
            $selected = [];

            foreach ($indexes as $index) {
                $destination = $discovered[$index];
                $provider = $destination['provider'] ?? null;
                $externalId = $destination['external_id'] ?? null;
                $name = $destination['name'] ?? null;

                if (! is_string($provider) || ! is_string($externalId) || trim($externalId) === '' || ! is_string($name)) {
                    throw new SocialAuthorizationException('destination_selection_invalid', 'A discovered destination is invalid.');
                }

                if (! in_array($provider, $this->providers->destinationNetworks($attempt->provider), true)) {
                    throw new SocialAuthorizationException('destination_network_mismatch', 'A discovered destination does not belong to the authorized provider.');
                }

                $account = SocialAccount::query()
                    ->where('enterprise_id', $channel->enterprise_id)
                    ->where('provider', $provider)
                    ->where('external_id', $externalId)
                    ->first();

                if ($account !== null) {
                    if ((int) $account->channel_id !== (int) $channel->id) {
                        throw new SocialAuthorizationException('destination_already_assigned', 'A discovered destination is already assigned to another channel.');
                    }
                    if ($account->social_identity_id !== null && (int) $account->social_identity_id !== (int) $identity->id) {
                        throw new SocialAuthorizationException('destination_identity_conflict', 'A destination is already linked to another authenticated identity.');
                    }

                    if ($account->social_identity_id === null) {
                        $account->social_identity_id = $identity->id;
                    }
                    if ($account->name !== $name) {
                        $account->name = $name;
                    }
                    $account->save();
                } else {
                    $account = SocialAccount::query()->create([
                        'enterprise_id' => $channel->enterprise_id,
                        'channel_id' => $channel->id,
                        'social_identity_id' => $identity->id,
                        'provider' => $provider,
                        'name' => $name,
                        'external_id' => $externalId,
                        'status' => SocialAccount::STATUS_ACTIVE,
                    ]);
                }

                $selected[] = $provider.':'.$externalId;
                $count++;
            }

            if ($attempt->channel_id === null) {
                $attempt->channel_id = $channel->id;
            }
            $attempt->selected_destinations = $selected;
            $attempt->destinations_attached_at = now();
            $attempt->save();

            $this->audit($attempt, 'destinations_attached', [
                'destination_count' => $count,
                'channel_id' => $channel->id,
            ], $actor->id, $attempt->integration_connection_id);

            return $count;
        });

        return $attached;
    }

    public function disconnect(User $actor, IntegrationConnection $connection): SocialRevocationResult
    {
        Gate::forUser($actor)->authorize('update', $connection);

        if ($connection->social_identity_id === null) {
            throw new SocialAuthorizationException('social_connection_required', 'This integration connection is not a social authorization grant.');
        }

        return $this->credentialManager->disconnect($connection, (int) $actor->id);
    }

    public function assertCanViewAttempt(User $actor, SocialAuthorizationAttempt $attempt): void
    {
        if ((int) $attempt->user_id !== (int) $actor->id) {
            throw new AuthorizationException('The authorization attempt belongs to another user.');
        }

        $this->authorizeAttemptContext($actor, $attempt);
    }

    private function authorizeAttemptContext(User $actor, SocialAuthorizationAttempt $attempt): void
    {
        $organization = Organization::query()->findOrFail($attempt->organization_id);
        Gate::forUser($actor)->authorize('update', $organization);

        if ($attempt->enterprise_id !== null) {
            $enterprise = Enterprise::query()->where('organization_id', $organization->id)->findOrFail($attempt->enterprise_id);
            Gate::forUser($actor)->authorize('update', $enterprise);
        }
    }

    private function failAttempt(SocialAuthorizationAttempt $attempt, string $failureCode): void
    {
        $fresh = SocialAuthorizationAttempt::query()->find($attempt->id);
        if ($fresh === null || $fresh->status !== SocialAuthorizationAttempt::STATUS_PROCESSING) {
            return;
        }

        $fresh->transitionTo(SocialAuthorizationAttempt::STATUS_FAILED);
        $fresh->failure_code = $this->safeFailureCode($failureCode);
        $fresh->save();
        $this->audit($fresh, 'authorization_failed', ['failure_code' => $fresh->failure_code]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        SocialAuthorizationAttempt $attempt,
        string $action,
        array $metadata = [],
        ?int $actorId = null,
        ?int $connectionId = null,
    ): void {
        SocialAuthorizationAudit::query()->create([
            'organization_id' => $attempt->organization_id,
            'enterprise_id' => $attempt->enterprise_id,
            'actor_id' => $actorId ?? $attempt->user_id,
            'integration_connection_id' => $connectionId ?? $attempt->integration_connection_id,
            'attempt_id' => (string) $attempt->id,
            'provider' => $attempt->provider,
            'destination_network' => $attempt->destination_network,
            'action' => $action,
            'correlation_id' => $attempt->correlation_id,
            'metadata' => $metadata,
        ]);
    }

    private function safeFailureCode(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z0-9_.-]{1,100}$/', $value) === 1) {
            return $value;
        }

        return 'provider_denied';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}