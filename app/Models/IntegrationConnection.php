<?php

namespace App\Models;

use App\Data\Integrations\CredentialReference;
use App\Services\IntegrationConfigurationService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['organization_id', 'enterprise_id', 'social_identity_id', 'provider', 'external_account_id', 'credential_reference', 'configuration', 'status', 'metadata'])]
class IntegrationConnection extends Model
{
    private bool $lifecycleTransitionAuthorized = false;

    private bool $credentialReferenceMutationAuthorized = false;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_RECONNECT_REQUIRED = 'reconnect_required';

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (IntegrationConnection $connection): void {
            if ($connection->exists && $connection->isDirty('status')) {
                if (! $connection->lifecycleTransitionAuthorized) {
                    throw new LogicException('Integration connection status can only change through its lifecycle methods.');
                }

                $connection->lifecycleTransitionAuthorized = false;
            }
            if ($connection->exists && $connection->social_identity_id !== null && $connection->isDirty('credential_reference')) {
                if (! $connection->credentialReferenceMutationAuthorized) {
                    throw new LogicException('Social credential references can only change through the authorization lifecycle.');
                }

                $connection->credentialReferenceMutationAuthorized = false;
            }

            if (! in_array($connection->status, [
                self::STATUS_ACTIVE,
                self::STATUS_DISABLED,
                self::STATUS_DEGRADED,
                self::STATUS_REVOKED,
                self::STATUS_EXPIRED,
                self::STATUS_RECONNECT_REQUIRED,
            ], true)) {
                throw new LogicException("Invalid integration connection status [{$connection->status}].");
            }

            if (trim((string) $connection->credential_reference) === '') {
                throw new LogicException('Integration connections require a credential reference.');
            }

            if ($connection->enterprise_id !== null) {
                $enterprise = Enterprise::query()->find($connection->enterprise_id);

                if ($enterprise === null || (int) $enterprise->organization_id !== (int) $connection->organization_id) {
                    throw new LogicException('Integration connection enterprise must belong to its organization.');
                }
            }

            if ($connection->exists && $connection->isDirty('organization_id')) {
                throw new LogicException('Integration connection organization cannot be changed.');
            }

            if ($connection->exists && $connection->isDirty('social_identity_id')) {
                throw new LogicException('An integration connection cannot be reassigned to a different social identity.');
            }

            $socialProviders = config('social_authorization.providers', []);
            if ($connection->social_identity_id === null && is_array($socialProviders) && array_key_exists((string) $connection->provider, $socialProviders)) {
                throw new LogicException('Social authorization connections must be created through the OAuth lifecycle.');
            }

            if ($connection->social_identity_id !== null) {
                $identity = SocialIdentity::query()->find($connection->social_identity_id);
                if ($identity === null
                    || (int) $identity->organization_id !== (int) $connection->organization_id
                    || $identity->provider !== $connection->provider
                    || $identity->external_id !== $connection->external_account_id) {
                    throw new LogicException('A social integration connection must match its identity network, external identifier, and organization.');
                }

                if ($connection->enterprise_id !== null && SocialAccount::query()
                    ->where('social_identity_id', $identity->id)
                    ->where('enterprise_id', '!=', $connection->enterprise_id)
                    ->exists()) {
                    throw new LogicException('An enterprise-scoped social connection cannot authorize destinations in another enterprise.');
                }
            }

            /** @var array<string, mixed>|null $configuration */
            $configuration = $connection->configuration;
            app(IntegrationConfigurationService::class)->validate($connection->provider, $configuration);

            /** @var array<string, mixed>|null $metadata */
            $metadata = $connection->metadata;
            self::assertSafeMetadata($metadata);
            self::assertSafeConfiguration($configuration);
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<SocialIdentity, $this> */
    public function socialIdentity(): BelongsTo
    {
        return $this->belongsTo(SocialIdentity::class);
    }

    public function isOperational(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function credentialReference(): CredentialReference
    {
        return new CredentialReference($this->credential_reference);
    }

    public function transitionTo(string $status): static
    {
        $allowed = match ($this->status) {
            self::STATUS_ACTIVE => [self::STATUS_DISABLED, self::STATUS_DEGRADED, self::STATUS_EXPIRED, self::STATUS_RECONNECT_REQUIRED, self::STATUS_REVOKED],
            self::STATUS_DISABLED => [self::STATUS_ACTIVE, self::STATUS_DEGRADED, self::STATUS_EXPIRED, self::STATUS_RECONNECT_REQUIRED, self::STATUS_REVOKED],
            self::STATUS_DEGRADED => [self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_EXPIRED, self::STATUS_RECONNECT_REQUIRED, self::STATUS_REVOKED],
            self::STATUS_EXPIRED => [self::STATUS_DISABLED, self::STATUS_RECONNECT_REQUIRED, self::STATUS_REVOKED],
            self::STATUS_RECONNECT_REQUIRED => [self::STATUS_DISABLED, self::STATUS_REVOKED],
            self::STATUS_REVOKED => [],
            default => throw new LogicException('Integration connection has no valid lifecycle state.'),
        };

        if (! in_array($status, [
            self::STATUS_ACTIVE,
            self::STATUS_DISABLED,
            self::STATUS_DEGRADED,
            self::STATUS_REVOKED,
            self::STATUS_EXPIRED,
            self::STATUS_RECONNECT_REQUIRED,
        ], true) || ! in_array($status, $allowed, true)) {
            throw new LogicException("Integration connection cannot transition from [{$this->status}] to [{$status}].");
        }

        $this->status = $status;
        $this->lifecycleTransitionAuthorized = true;

        return $this;
    }

    public function reauthorize(string $credentialReference): static
    {
        if (! in_array($this->status, [self::STATUS_EXPIRED, self::STATUS_RECONNECT_REQUIRED, self::STATUS_REVOKED], true)) {
            throw new LogicException('Only an expired, revoked, or reconnect-required connection can be reauthorized.');
        }

        if (trim($credentialReference) === '') {
            throw new LogicException('Reauthorization requires a new credential reference.');
        }

        $this->credential_reference = $credentialReference;
        $this->status = self::STATUS_ACTIVE;
        $this->lifecycleTransitionAuthorized = true;
        $this->credentialReferenceMutationAuthorized = true;

        return $this;
    }

    public function rotateCredentialReference(string $credentialReference): static
    {
        if ($this->social_identity_id === null || trim($credentialReference) === '') {
            throw new LogicException('Credential rotation requires a linked social identity and a new reference.');
        }

        $this->credential_reference = $credentialReference;
        $this->credentialReferenceMutationAuthorized = true;

        return $this;
    }

    /** @param array<string, mixed>|null $configuration */
    private static function assertSafeConfiguration(?array $configuration): void
    {
        if ($configuration === null) {
            return;
        }

        $sensitive = ['token', 'access_token', 'refresh_token', 'secret', 'client_secret', 'password', 'api_key', 'private_key'];

        $walk = function (array $values) use (&$walk, $sensitive): void {
            foreach ($values as $key => $value) {
                $normalized = strtolower((string) $key);

                foreach ($sensitive as $needle) {
                    if ($normalized === $needle || str_contains($normalized, $needle)) {
                        throw new LogicException('Integration connection configuration cannot contain credential material.');
                    }
                }

                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($configuration);
    }

    /** @param array<string, mixed>|null $metadata */
    private static function assertSafeMetadata(?array $metadata): void
    {
        if ($metadata === null) {
            return;
        }

        $sensitive = ['token', 'access_token', 'refresh_token', 'secret', 'client_secret', 'password', 'api_key', 'private_key'];

        $walk = function (array $values) use (&$walk, $sensitive): void {
            foreach ($values as $key => $value) {
                $normalized = strtolower((string) $key);

                foreach ($sensitive as $needle) {
                    if ($normalized === $needle || str_contains($normalized, $needle)) {
                        throw new LogicException('Integration connection metadata cannot contain credential material.');
                    }
                }

                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($metadata);
    }
}