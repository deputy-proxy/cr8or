<?php

namespace App\Models;

use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property \Carbon\CarbonImmutable|null $github_updated_at
 * @property \Carbon\CarbonImmutable|null $github_deleted_at
 */
#[Fillable(['enterprise_id', 'integration_repository_id', 'github_repository_id', 'assignees', 'repository', 'external_id', 'number', 'title', 'state', 'labels', 'author_login', 'github_created_at', 'github_updated_at', 'github_closed_at', 'github_deleted_at', 'url', 'last_synced_at'])]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $issue): void {
            if ($issue->integration_repository_id === null) {
                return;
            }

            $repository = IntegrationRepository::query()->find($issue->integration_repository_id);
            if ($repository === null || $repository->enterprise_id === null
                || (int) $repository->enterprise_id !== (int) $issue->enterprise_id
                || (int) $repository->github_repository_id !== (int) $issue->github_repository_id) {
                throw new LogicException('A GitHub issue snapshot must match its assigned repository and Enterprise.');
            }
        });
    }

    protected function casts(): array
    {
        return ['labels' => 'array', 'assignees' => 'array', 'github_created_at' => 'immutable_datetime', 'github_updated_at' => 'immutable_datetime', 'github_closed_at' => 'immutable_datetime', 'github_deleted_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<IntegrationRepository, $this> */
    public function integrationRepository(): BelongsTo
    {
        return $this->belongsTo(IntegrationRepository::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}
