<?php

namespace App\Console\Commands;

use App\Services\GitHubLegacyRepositoryMigrationService;
use Illuminate\Console\Command;

class MigrateLegacyGitHubRepositories extends Command
{
    protected $signature = 'github:migrate-legacy-repositories';

    protected $description = 'Resolve legacy Enterprise GitHub repository settings and migrate them to repository associations.';

    public function handle(GitHubLegacyRepositoryMigrationService $migration): int
    {
        $result = $migration->migrate();
        $this->info('Migrated: '.$result['migrated'].'; skipped: '.$result['skipped'].'; unresolved: '.count($result['unresolved']));
        foreach ($result['unresolved'] as $entry) {
            $this->warn($entry);
        }

        return $result['unresolved'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
