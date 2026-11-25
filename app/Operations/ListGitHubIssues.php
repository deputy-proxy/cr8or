<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\IntegrationRepository;
use App\Models\Issue;
use App\Models\User;
use App\Services\AccessibleEnterpriseResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ListGitHubIssues implements Operation
{
    public function __construct(private readonly AccessibleEnterpriseResolver $enterprises) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(User $actor, array $input): array
    {
        $contextEnterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        Gate::forUser($actor)->authorize('view', $contextEnterprise);

        $scope = (string) ($input['scope'] ?? 'enterprise');
        if (! in_array($scope, ['enterprise', 'all_accessible'], true)) {
            throw ValidationException::withMessages(['scope' => 'Scope must be enterprise or all_accessible.']);
        }

        if ($scope === 'all_accessible') {
            $targetEnterprises = $this->enterprises->forActor($actor);
            $maxItems = max(1, (int) ($input['max_items'] ?? 1000));
            $totalIssues = 0;
            $reports = [];
            $sourceDataComplete = true;

            foreach ($targetEnterprises as $targetEnterprise) {
                $remaining = $maxItems - $totalIssues;
                $report = $this->listForEnterprise($actor, $targetEnterprise, $input, $remaining);
                $enterpriseTotal = $report['pagination']['total'];
                $totalIssues += $enterpriseTotal;
                $sourceDataComplete = $sourceDataComplete && $report['sync']['availability'] === 'ready';
                $reports[] = [
                    'enterprise_id' => (int) $targetEnterprise->getKey(),
                    'enterprise_name' => $targetEnterprise->name,
                    'total_open_issues' => $enterpriseTotal,
                    'issues' => $report['items'],
                    'source_data_complete' => $report['sync']['availability'] === 'ready',
                    'sync' => $report['sync'],
                ];
            }

            return [
                'scope' => 'all_accessible',
                'enterprise_ids' => $targetEnterprises->modelKeys(),
                'enterprises' => $reports,
                'total_open_issues' => $totalIssues,
                'max_items' => $maxItems,
                'result_set_complete' => true,
                'source_data_complete' => $sourceDataComplete,
            ];
        }

        return [
            'scope' => 'enterprise',
            'enterprise_id' => (int) $contextEnterprise->getKey(),
            'enterprise_name' => $contextEnterprise->name,
            ...$this->listForEnterprise($actor, $contextEnterprise, $input),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int}, sync: array<string, mixed>}
     */
    private function listForEnterprise(User $actor, Enterprise $enterprise, array $input, ?int $completenessLimit = null): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        $repositories = $enterprise->integrationRepositories()->with('connection')->get();
        $activeRepositories = $repositories->filter(fn (IntegrationRepository $repository): bool => $repository->status === IntegrationRepository::STATUS_ACTIVE
            && (int) $repository->organization_id === (int) $enterprise->organization_id
            && $repository->connection !== null
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

        if ($completenessLimit !== null) {
            $total = $query->count();
            if ($total > $completenessLimit) {
                throw ValidationException::withMessages([
                    'max_items' => "Enterprise {$enterprise->name} exceeds the remaining {$completenessLimit}-issue completeness limit. Increase max_items; no partial result was returned.",
                ]);
            }

            /** @var list<Issue> $issueItems */
            $issueItems = $query->limit(max(1, $total))->get()->all();
            $pagination = [
                'page' => 1,
                'per_page' => max(1, $completenessLimit),
                'total' => $total,
                'last_page' => 1,
            ];
        } else {
            $issues = $query->paginate((int) ($input['per_page'] ?? 50), ['*'], 'page', (int) ($input['page'] ?? 1));

            /** @var list<Issue> $issueItems */
            $issueItems = $issues->items();
            $pagination = [
                'page' => $issues->currentPage(),
                'per_page' => $issues->perPage(),
                'total' => $issues->total(),
                'last_page' => $issues->lastPage(),
            ];
        }

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
            'pagination' => $pagination,
            'sync' => [
                'availability' => $availability,
                'status' => $syncStatus,
                'synced_at' => $legacySyncedAt === null ? null : Carbon::parse($legacySyncedAt)->toIso8601String(),
                'is_stale' => $isStale,
                'stale_after_minutes' => $staleAfterMinutes,
                'has_error' => $enterprise->github_issues_sync_error !== null,
                'repositories' => $repositoryStates,
                'warnings' => $warnings,
            ],
        ];
    }
}
