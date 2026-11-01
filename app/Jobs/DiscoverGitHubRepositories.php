<?php

namespace App\Jobs;

use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRepository;
use App\Services\GitHubRepositoryDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DiscoverGitHubRepositories implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return 'github-repository-discovery:'.$this->connectionId;
    }

    public function handle(GitHubRepositoryDiscoveryService $discovery): void
    {
        $connection = IntegrationConnection::query()->find($this->connectionId);
        if ($connection !== null && $connection->provider === 'github' && $connection->isOperational()) {
            $repositories = $discovery->discover($connection);
            $enterpriseIds = collect($repositories)->pluck('enterprise_id')->filter()->unique()->values();
            $legacySyncEnterpriseIds = Enterprise::query()->whereIn('id', $enterpriseIds)
                ->whereNotNull('github_repository')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('integration_repositories as legacy_repository')
                        ->whereColumn('legacy_repository.organization_id', 'enterprises.organization_id')
                        ->whereRaw('LOWER(legacy_repository.full_name) = LOWER(enterprises.github_repository)');
                })->pluck('id')->map(fn ($id): int => (int) $id)->all();

            foreach ($repositories as $repository) {
                if ($repository->enterprise_id === null || $repository->status !== IntegrationRepository::STATUS_ACTIVE
                    || in_array((int) $repository->enterprise_id, $legacySyncEnterpriseIds, true)) {
                    continue;
                }

                SyncIntegrationRepositoryIssues::dispatch((int) $repository->id)->afterCommit();
            }
        }
    }
}
