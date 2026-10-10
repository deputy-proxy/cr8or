<?php

namespace App\Console\Commands;

use App\Jobs\SyncEnterpriseGitHubIssues as SyncEnterpriseGitHubIssuesJob;
use App\Models\Enterprise;
use Illuminate\Console\Command;

class SyncEnterpriseGitHubIssues extends Command
{
    protected $signature = 'enterprise-portfolio:sync-github-issues';

    protected $description = 'Queue GitHub issue synchronization for configured Enterprises.';

    public function handle(): int
    {
        Enterprise::query()
            ->whereNotNull('github_repository')
            ->where(function ($query): void {
                $query->whereHas('integrationConnections', fn ($connection) => $connection->where('provider', 'github')->where('status', 'active'))
                    ->orWhereHas('organization.integrationConnections', fn ($connection) => $connection->whereNull('enterprise_id')->where('provider', 'github')->where('status', 'active'));
            })
            ->orderBy('id')
            ->chunkById(100, function ($enterprises): void {
                foreach ($enterprises as $enterprise) {
                    SyncEnterpriseGitHubIssuesJob::dispatch((int) $enterprise->id);
                }
            });

        $this->info('GitHub issue synchronization jobs queued.');

        return self::SUCCESS;
    }
}
