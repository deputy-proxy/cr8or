<?php

namespace App\Jobs;

use App\Models\Enterprise;
use App\Services\GitHubIssueSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncEnterpriseGitHubIssues implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 86_400;

    public function __construct(public readonly int $enterpriseId) {}

    public function uniqueId(): string
    {
        return 'enterprise-github-issues:'.$this->enterpriseId;
    }

    public function handle(GitHubIssueSyncService $sync): void
    {
        $enterprise = Enterprise::query()->find($this->enterpriseId);
        if ($enterprise === null || ! is_string($enterprise->github_repository) || $enterprise->github_repository === '') {
            return;
        }

        $sync->sync($enterprise);
    }
}
