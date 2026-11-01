<?php

namespace App\Jobs;

use App\Models\IntegrationRepository;
use App\Services\GitHubIssueSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RefreshGitHubIssueSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $repositoryId, public readonly int $issueNumber) {}

    public function uniqueId(): string
    {
        return 'github-issue-refresh:'.$this->repositoryId.':'.$this->issueNumber;
    }

    public function handle(GitHubIssueSyncService $sync): void
    {
        $repository = IntegrationRepository::query()->find($this->repositoryId);
        if ($repository !== null) {
            $sync->refreshIssue($repository, $this->issueNumber);
        }
    }
}
