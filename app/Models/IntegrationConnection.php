<?php

namespace App\Models;

use App\Data\Integrations\CredentialReference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['organization_id', 'enterprise_id', 'provider', 'external_account_id', 'credential_reference', 'status', 'metadata'])]
class IntegrationConnection extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_REVOKED = 'revoked';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (IntegrationConnection $connection): void {
            if (! in_array($connection->status, [
                self::STATUS_ACTIVE,
                self::STATUS_DISABLED,
                self::STATUS_DEGRADED,
                self::STATUS_REVOKED,
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

            /** @var array<string, mixed>|null $metadata */
            $metadata = $connection->metadata;
            self::assertSafeMetadata($metadata);
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
            self::STATUS_ACTIVE => [self::STATUS_DISABLED, self::STATUS_DEGRADED, self::STATUS_REVOKED],
            self::STATUS_DISABLED => [self::STATUS_ACTIVE, self::STATUS_DEGRADED, self::STATUS_REVOKED],
            self::STATUS_DEGRADED => [self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_REVOKED],
            self::STATUS_REVOKED => [],
            default => throw new LogicException('Integration connection has no valid lifecycle state.'),
        };

        if (! in_array($status, [
            self::STATUS_ACTIVE,
            self::STATUS_DISABLED,
            self::STATUS_DEGRADED,
            self::STATUS_REVOKED,
        ], true) || ! in_array($status, $allowed, true)) {
            throw new LogicException("Integration connection cannot transition from [{$this->status}] to [{$status}].");
        }

        $this->status = $status;

        return $this;
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