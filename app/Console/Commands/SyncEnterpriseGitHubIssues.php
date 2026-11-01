<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverGitHubRepositories;
use App\Jobs\SyncEnterpriseGitHubIssues as SyncEnterpriseGitHubIssuesJob;
use App\Jobs\SyncIntegrationRepositoryIssues;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRepository;
use Illuminate\Console\Command;

class SyncEnterpriseGitHubIssues extends Command
{
    protected $signature = 'enterprise-portfolio:sync-github-issues';

    protected $description = 'Reconcile GitHub App repositories and queue issue synchronization.';

    public function handle(): int
    {
        IntegrationConnection::query()->where('provider', 'github')
            ->where('status', IntegrationConnection::STATUS_ACTIVE)
            ->whereNotNull('github_installation_id')->orderBy('id')
            ->chunkById(100, function ($connections): void {
                foreach ($connections as $connection) {
                    DiscoverGitHubRepositories::dispatch((int) $connection->id);
                }
            });

        IntegrationRepository::query()
            ->where('status', IntegrationRepository::STATUS_ACTIVE)
            ->whereNotNull('enterprise_id')
            ->whereHas('connection', fn ($connection) => $connection->where('provider', 'github')->where('status', 'active'))
            ->whereDoesntHave('enterprise', function ($enterprise): void {
                $enterprise->whereNotNull('github_repository')->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('integration_repositories as legacy_repository')
                        ->whereColumn('legacy_repository.organization_id', 'enterprises.organization_id')
                        ->whereRaw('LOWER(legacy_repository.full_name) = LOWER(enterprises.github_repository)');
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($repositories): void {
                foreach ($repositories as $repository) {
                    SyncIntegrationRepositoryIssues::dispatch((int) $repository->id);
                }
            });

        Enterprise::query()->whereNotNull('github_repository')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('integration_repositories')
                    ->whereColumn('integration_repositories.organization_id', 'enterprises.organization_id')
                    ->whereColumn('integration_repositories.full_name', 'enterprises.github_repository');
            })
            ->where(function ($query): void {
                $query->whereHas('integrationConnections', fn ($connection) => $connection->where('provider', 'github')->where('status', 'active'))
                    ->orWhereHas('organization.integrationConnections', fn ($connection) => $connection->whereNull('enterprise_id')->where('provider', 'github')->where('status', 'active'));
            })->orderBy('id')->chunkById(100, function ($enterprises): void {
                foreach ($enterprises as $enterprise) {
                    SyncEnterpriseGitHubIssuesJob::dispatch((int) $enterprise->id);
                }
            });

        $this->info('GitHub issue synchronization jobs queued.');

        return self::SUCCESS;
    }
}
