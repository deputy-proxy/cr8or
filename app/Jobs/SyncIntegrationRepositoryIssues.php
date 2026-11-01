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

final class SyncIntegrationRepositoryIssues implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $repositoryId) {}

    public function uniqueId(): string
    {
        return 'github-repository-sync:'.$this->repositoryId;
    }

    public function handle(GitHubIssueSyncService $sync): void
    {
        $repository = IntegrationRepository::query()->find($this->repositoryId);
        if ($repository !== null && $repository->enterprise_id !== null && $repository->status === IntegrationRepository::STATUS_ACTIVE) {
            $sync->syncRepository($repository);
        }
    }
}
