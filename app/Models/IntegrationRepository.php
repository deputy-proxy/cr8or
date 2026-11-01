<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property \Carbon\CarbonImmutable|null $last_synced_at
 * @property \Carbon\CarbonImmutable|null $github_updated_at
 */
#[Fillable(['integration_connection_id', 'organization_id', 'enterprise_id', 'github_repository_id', 'github_account_id', 'owner_login', 'name', 'full_name', 'url', 'default_branch', 'is_private', 'status', 'sync_status', 'last_synced_at', 'last_sync_error', 'github_updated_at'])]
class IntegrationRepository extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACCESSIBLE = 'inaccessible';

    public const STATUS_DISABLED = 'disabled';

    protected function casts(): array
    {
        return ['is_private' => 'boolean', 'last_synced_at' => 'immutable_datetime', 'github_updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $repository): void {
            if ($repository->exists && ($repository->isDirty('integration_connection_id') || $repository->isDirty('organization_id') || $repository->isDirty('github_repository_id'))) {
                throw new LogicException('GitHub repository identity and owning connection cannot be changed.');
            }

            if ($repository->exists && $repository->getOriginal('enterprise_id') !== null
                && $repository->enterprise_id !== null
                && (int) $repository->getOriginal('enterprise_id') !== (int) $repository->enterprise_id) {
                throw new LogicException('Unassign a GitHub repository before assigning it to another Enterprise.');
            }

            $connection = IntegrationConnection::query()->find($repository->integration_connection_id);
            if ($connection === null || $connection->provider !== 'github' || (int) $connection->organization_id !== (int) $repository->organization_id) {
                throw new LogicException('A GitHub repository must belong to a GitHub connection in the same organization.');
            }

            if ((int) $repository->github_repository_id < 1 || trim((string) $repository->owner_login) === ''
                || trim((string) $repository->name) === ''
                || strcasecmp((string) $repository->full_name, $repository->owner_login.'/'.$repository->name) !== 0
                || parse_url((string) $repository->url, PHP_URL_SCHEME) !== 'https'
                || parse_url((string) $repository->url, PHP_URL_HOST) !== 'github.com'
                || strcasecmp((string) parse_url((string) $repository->url, PHP_URL_PATH), '/'.$repository->full_name) !== 0) {
                throw new LogicException('GitHub repository identity fields must match a canonical github.com repository.');
            }

            if ($connection->enterprise_id !== null && $repository->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $repository->enterprise_id) {
                throw new LogicException('An Enterprise-scoped GitHub connection cannot serve another Enterprise.');
            }

            if ($repository->enterprise_id !== null) {
                $enterprise = Enterprise::query()->find($repository->enterprise_id);
                if ($enterprise === null || (int) $enterprise->organization_id !== (int) $repository->organization_id) {
                    throw new LogicException('A GitHub repository can only be assigned to an Enterprise in its connection organization.');
                }

                $alreadyAssigned = self::query()->where('github_repository_id', $repository->github_repository_id)
                    ->whereNotNull('enterprise_id')->whereKeyNot($repository->getKey())
                    ->where('enterprise_id', '!=', $repository->enterprise_id)->exists();
                if ($alreadyAssigned) {
                    throw new LogicException('A GitHub repository cannot be assigned to multiple Enterprises.');
                }
            }

            if (! in_array($repository->status, [self::STATUS_ACTIVE, self::STATUS_INACCESSIBLE, self::STATUS_DISABLED], true)) {
                throw new LogicException('Invalid GitHub repository status.');
            }
        });
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }
}
