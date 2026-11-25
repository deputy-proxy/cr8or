<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\IntegrationRepository;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

final class ListGitHubIssues implements Operation
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int}, sync: array<string, mixed>}
     */
    public function execute(User $actor, array $input): array
    {
        $enterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        Gate::forUser($actor)->authorize('view', $enterprise);

        $repositories = $enterprise->integrationRepositories()->with('connection')->get();
        $activeRepositories = $repositories->filter(fn (IntegrationRepository $repository): bool => $repository->status === IntegrationRepository::STATUS_ACTIVE
            && $repository->connection !== null
            && (int) $repository->organization_id === (int) $enterprise->organization_id
            && $repository->connection->provider === 'github'
            && (int) $repository->connection->organization_id === (int) $enterprise->organization_id
            && $repository->connection->isOperational()
            && ($repository->connection->enterprise_id === null || (int) $repository->connection->enterprise_id === (int) $enterprise->getKey())
        );
        $activeRepositoryIds = $activeRepositories->modelKeys();
        $hasLegacyRepository = is_string($enterprise->github_repository) && trim($enterprise->github_repository) !== '';
        $legacyRepositoryManagedByGitHubApp = $hasLegacyRepository && IntegrationRepository::query()
            ->where('organization_id', $enterprise->organization_id)
            ->whereRaw('LOWER(full_name) = ?', [strtolower($enterprise->github_repository)])
            ->exists();
        $legacyRepositoryAvailableViaAssignment = $hasLegacyRepository && $activeRepositories->contains(
            fn (IntegrationRepository $repository): bool => strcasecmp($repository->full_name, $enterprise->github_repository) === 0,
        );
        $includeLegacySnapshots = $hasLegacyRepository && ! $legacyRepositoryManagedByGitHubApp;

        $query = $enterprise->issues()
            ->where('state', 'open')
            ->whereNull('github_deleted_at')
            ->where(function (Builder $query) use ($activeRepositoryIds, $includeLegacySnapshots, $enterprise): void {
                if ($activeRepositoryIds !== []) {
                    $query->whereIn('integration_repository_id', $activeRepositoryIds);
                }

                if ($includeLegacySnapshots) {
                    $method = $activeRepositoryIds === [] ? 'where' : 'orWhere';
                    $query->{$method}(function (Builder $legacy) use ($enterprise): void {
                        $legacy->whereNull('integration_repository_id')
                            ->where('repository', $enterprise->github_repository);
                    });
                }

                if ($activeRepositoryIds === [] && ! $includeLegacySnapshots) {
                    $query->whereRaw('1 = 0');
                }
            })
            ->when(isset($input['search']), fn (Builder $query) => $query->where('title', 'like', '%'.(string) $input['search'].'%'))
            ->when(isset($input['repository']), fn (Builder $query) => $query->where('repository', $input['repository']))
            ->orderByDesc('github_updated_at')
            ->orderByDesc('number');

        $issues = $query->paginate((int) ($input['per_page'] ?? 50), ['*'], 'page', (int) ($input['page'] ?? 1));

        $staleAfterMinutes = max(1, (int) config('services.github.issue_sync_stale_after_minutes', 1440));
        $staleCutoff = now()->subMinutes($staleAfterMinutes);

        $repositoryStates = $repositories->map(static fn (IntegrationRepository $repository): array => [
            'repository' => $repository->full_name,
            'status' => $repository->status,
            'connection_status' => $repository->connection?->status,
            'sync_status' => $repository->sync_status,
            'last_synced_at' => $repository->last_synced_at === null ? null : Carbon::parse($repository->last_synced_at)->toIso8601String(),
            'is_stale' => $repository->last_synced_at === null || Carbon::parse($repository->last_synced_at)->lt($staleCutoff),
            'has_sync_error' => $repository->last_sync_error !== null,
            'included' => $activeRepositories->contains('id', $repository->getKey()),
        ])->values()->all();

        $warnings = [];
        foreach ($repositoryStates as $repositoryState) {
            if (! $repositoryState['included']) {
                $warnings[] = 'Repository '.$repositoryState['repository'].' is not currently available for issue reporting.';
            } elseif ($repositoryState['sync_status'] !== 'succeeded' || $repositoryState['last_synced_at'] === null) {
                $warnings[] = 'Repository '.$repositoryState['repository'].' has no confirmed successful issue synchronization.';
            } elseif ($repositoryState['is_stale']) {
                $warnings[] = 'Repository '.$repositoryState['repository'].' issue data is stale.';
            }
        }

        $configured = $repositories->isNotEmpty() || $hasLegacyRepository;
        $syncStatus = $enterprise->github_issues_sync_status;
        $legacySyncedAt = $enterprise->github_issues_synced_at;
        $legacyIsStale = $legacySyncedAt === null || Carbon::parse($legacySyncedAt)->lt($staleCutoff);

        if ($hasLegacyRepository && $legacyRepositoryManagedByGitHubApp && ! $legacyRepositoryAvailableViaAssignment) {
            $warnings[] = 'The configured legacy repository is managed through GitHub App assignments but is not currently available to this Enterprise.';
        } elseif ($includeLegacySnapshots && $repositories->isEmpty()) {
            if ($syncStatus !== 'succeeded' || $legacySyncedAt === null) {
                $warnings[] = 'The legacy GitHub repository has no confirmed successful issue synchronization.';
            } elseif ($legacyIsStale) {
                $warnings[] = 'The legacy GitHub repository issue data is stale.';
            }
        }

        $isStale = $includeLegacySnapshots && $repositories->isEmpty()
            ? $legacyIsStale
            : $activeRepositories->contains(fn (IntegrationRepository $repository): bool => $repository->last_synced_at === null || Carbon::parse($repository->last_synced_at)->lt($staleCutoff)
            );

        $availability = ! $configured
            ? 'not_configured'
            : (($activeRepositories->isEmpty() && ! $includeLegacySnapshots) || ($syncStatus === 'failed' && $activeRepositories->isEmpty())
                ? 'unavailable'
                : ($warnings === [] ? 'ready' : 'partial'));

        /** @var list<Issue> $issueItems */
        $issueItems = $issues->items();

        return [
            'items' => array_map(static fn (Issue $issue): array => [
                'id' => (int) $issue->getKey(),
                'enterprise_id' => (int) $issue->enterprise_id,
                'repository' => $issue->repository,
                'number' => (int) $issue->number,
                'title' => $issue->title,
                'state' => $issue->state,
                'labels' => $issue->labels ?? [],
                'assignees' => $issue->assignees ?? [],
                'author' => $issue->author_login,
                'url' => $issue->url,
                'github_updated_at' => $issue->github_updated_at === null ? null : Carbon::parse($issue->github_updated_at)->toIso8601String(),
                'last_synced_at' => Carbon::parse($issue->last_synced_at)->toIso8601String(),
            ], $issueItems),
            'pagination' => [
                'page' => $issues->currentPage(),
                'per_page' => $issues->perPage(),
                'total' => $issues->total(),
                'last_page' => $issues->lastPage(),
            ],
            'sync' => [
                'availability' => $availability,
                'status' => $syncStatus,
                'synced_at' => $enterprise->github_issues_synced_at === null ? null : Carbon::parse($enterprise->github_issues_synced_at)->toIso8601String(),
                'is_stale' => $isStale,
                'stale_after_minutes' => $staleAfterMinutes,
                'has_error' => $enterprise->github_issues_sync_error !== null,
                'repositories' => $repositoryStates,
                'warnings' => $warnings,
            ],
        ];
    }
}
