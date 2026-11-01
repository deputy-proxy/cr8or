<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRepository;
use App\Models\Issue;
use App\Providers\GitHubCredentialResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class GitHubIssueSyncService
{
    public function __construct(
        private readonly GitHubCredentialResolver $credentials,
        private readonly IntegrationRegistry $registry,
    ) {}

    public function sync(Enterprise $enterprise): int
    {
        $enterprise->forceFill(['github_issues_sync_status' => 'syncing', 'github_issues_sync_error' => null])->save();
        try {
            $repositories = $enterprise->integrationRepositories()
                ->where('status', IntegrationRepository::STATUS_ACTIVE)
                ->whereHas('connection', fn ($query) => $query->where('provider', 'github')->where('status', IntegrationConnection::STATUS_ACTIVE))
                ->with('connection')
                ->get();

            $hasRepositoryAssociations = $enterprise->integrationRepositories()->exists();
            $legacyRepositoryDiscovered = is_string($enterprise->github_repository)
                && IntegrationRepository::query()->where('organization_id', $enterprise->organization_id)
                    ->whereRaw('LOWER(full_name) = ?', [strtolower($enterprise->github_repository)])->exists();

            $hasLegacyRepository = is_string($enterprise->github_repository) && trim($enterprise->github_repository) !== '';

            if ($repositories->isEmpty() && ($legacyRepositoryDiscovered || ($hasRepositoryAssociations && ! $hasLegacyRepository))) {
                $enterprise->forceFill(['github_issues_sync_status' => 'skipped', 'github_issues_sync_error' => null])->save();

                return 0;
            }

            if ($repositories->isNotEmpty()) {
                $total = 0;
                $failures = [];
                foreach ($repositories as $repository) {
                    try {
                        $total += $this->syncRepository($repository);
                    } catch (Throwable $exception) {
                        $failures[] = $repository->full_name.': '.Str::limit($exception->getMessage(), 300, '');
                    }
                }
                if ($failures !== []) {
                    throw new RuntimeException('One or more GitHub repositories failed to synchronize: '.implode('; ', $failures));
                }
                if ($hasLegacyRepository && ! $legacyRepositoryDiscovered) {
                    $total += $this->syncLegacyRepository($enterprise);
                }
                $enterprise->forceFill(['github_issues_sync_status' => 'succeeded', 'github_issues_synced_at' => now(), 'github_issues_sync_error' => null])->save();

                return $total;
            }

            $count = $this->syncLegacyRepository($enterprise);

            return $count;
        } catch (Throwable $exception) {
            $enterprise->forceFill(['github_issues_sync_status' => 'failed', 'github_issues_sync_error' => Str::limit($exception->getMessage(), 500, '')])->save();
            throw $exception;
        }
    }

    public function syncRepository(IntegrationRepository $repository): int
    {
        $repository->loadMissing('connection');
        $connection = $repository->connection;
        if ($repository->status !== IntegrationRepository::STATUS_ACTIVE || $connection === null
            || $connection->provider !== 'github' || ! $connection->isOperational()
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $repository->enterprise_id)
            || $repository->enterprise_id === null) {
            return 0;
        }

        $repository->forceFill(['sync_status' => 'syncing', 'last_sync_error' => null])->save();
        try {
            $this->registry->assertOperation('source_control', 'github', 'repository.execute');
            $token = $this->credentials->resolveAccessToken($connection);
            $client = Http::baseUrl('https://api.github.com')->withToken($token)->acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'CR8OR'])
                ->timeout((int) config('services.github.timeout', 15));
            $since = $repository->last_synced_at?->subMinutes(5)->toIso8601String();
            $count = 0;

            for ($page = 1; $page <= 100; $page++) {
                $params = ['state' => 'all', 'per_page' => 100, 'page' => $page, 'sort' => 'updated', 'direction' => 'asc'];
                if ($since !== null) {
                    $params['since'] = $since;
                }
                $response = $this->requestPage($client, '/repos/'.$repository->full_name.'/issues', $params);
                if (! $response->successful()) {
                    if ($response->status() === 404 || $response->status() === 403) {
                        $repository->forceFill(['status' => IntegrationRepository::STATUS_INACCESSIBLE])->save();
                    }
                    throw new RuntimeException('GitHub issue synchronization failed with HTTP '.$response->status().'.');
                }
                $items = $response->json();
                if (! is_array($items) || ! array_is_list($items)) {
                    throw new RuntimeException('GitHub returned an invalid issue page.');
                }
                foreach ($items as $item) {
                    if (! is_array($item) || isset($item['pull_request'])) {
                        continue;
                    }
                    $mapped = $this->mapIssue($repository, $item);
                    if ($mapped === null) {
                        continue;
                    }
                    $this->persistIssue($repository, $mapped);
                    $count++;
                }
                if (count($items) < 100) {
                    $repository->forceFill([
                        'sync_status' => 'succeeded', 'last_synced_at' => now(), 'last_sync_error' => null,
                    ])->save();
                    $this->refreshEnterpriseSyncState($repository);

                    return $count;
                }
            }
            throw new RuntimeException('GitHub issue synchronization exceeded the 100-page safety limit.');
        } catch (Throwable $exception) {
            $repository->forceFill([
                'sync_status' => 'failed',
                'last_sync_error' => Str::limit($exception->getMessage(), 500, ''),
            ])->save();
            $this->refreshEnterpriseSyncState($repository, $exception->getMessage());
            throw $exception;
        }
    }

    /** @param array<string, mixed> $item */
    private function persistIssue(IntegrationRepository $repository, array $item): void
    {
        DB::transaction(function () use ($repository, $item): void {
            $issue = Issue::query()->where('integration_repository_id', $repository->id)
                ->where('external_id', $item['external_id'])->first();
            if ($issue !== null && $issue->github_updated_at !== null && $item['github_updated_at'] !== null
                && $issue->github_updated_at->greaterThan($item['github_updated_at'])) {
                if ((int) $issue->enterprise_id !== (int) $repository->enterprise_id
                    || (int) $issue->integration_repository_id !== (int) $repository->id
                    || (int) $issue->github_repository_id !== (int) $repository->github_repository_id
                    || $issue->repository !== $repository->full_name) {
                    $issue->forceFill([
                        'enterprise_id' => $repository->enterprise_id,
                        'integration_repository_id' => $repository->id,
                        'github_repository_id' => $repository->github_repository_id,
                        'repository' => $repository->full_name,
                    ])->save();
                }

                return;
            }
            if ($issue === null) {
                $issue = Issue::query()->where('enterprise_id', $repository->enterprise_id)
                    ->where('repository', $repository->full_name)->where('external_id', $item['external_id'])->first();
            }
            $issue ??= new Issue;
            $issue->fill($item);
            $issue->forceFill([
                'enterprise_id' => $repository->enterprise_id,
                'integration_repository_id' => $repository->id,
                'github_repository_id' => $repository->github_repository_id,
            ])->save();
        });
    }

    private function refreshEnterpriseSyncState(IntegrationRepository $repository, ?string $failure = null): void
    {
        $enterprise = $repository->enterprise;
        if ($enterprise === null) {
            return;
        }
        $active = $enterprise->integrationRepositories()->where('status', IntegrationRepository::STATUS_ACTIVE)
            ->whereHas('connection', fn ($query) => $query->where('status', IntegrationConnection::STATUS_ACTIVE))
            ->get(['sync_status']);
        if ($failure !== null || $active->contains(fn (IntegrationRepository $item): bool => $item->sync_status === 'failed')) {
            $enterprise->forceFill(['github_issues_sync_status' => 'failed', 'github_issues_sync_error' => Str::limit($failure ?? 'One or more GitHub repositories failed to synchronize.', 500, '')])->save();

            return;
        }
        if ($active->isNotEmpty() && $active->every(fn (IntegrationRepository $item): bool => $item->sync_status === 'succeeded')) {
            $enterprise->forceFill(['github_issues_sync_status' => 'succeeded', 'github_issues_synced_at' => now(), 'github_issues_sync_error' => null])->save();
        } else {
            $enterprise->forceFill(['github_issues_sync_status' => 'syncing', 'github_issues_sync_error' => null])->save();
        }
    }

    /** @param array<string, int|string> $params */
    private function requestPage(PendingRequest $client, string $path, array $params): Response
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $client->get($path, $params);
            } catch (ConnectionException $exception) {
                if ($attempt === 3) {
                    throw new RuntimeException('GitHub API connection failed after retry attempts.', previous: $exception);
                }
                usleep(250_000 * $attempt);

                continue;
            }
            $limited = $response->status() === 429 || ($response->status() === 403
                && ($response->header('X-RateLimit-Remaining') === '0' || trim((string) $response->header('Retry-After')) !== ''));
            if ($limited) {
                if ($attempt === 3) {
                    throw new RuntimeException('GitHub API rate limit exceeded after retry attempts.');
                }
                $retryAfter = max(1, (int) ($response->header('Retry-After') ?: 1));
                if ($retryAfter > 2) {
                    throw new RuntimeException('GitHub API rate limit exceeded; retry after '.$retryAfter.' seconds.');
                }
                usleep($retryAfter * 1_000_000);

                continue;
            }
            if ($response->serverError() && $attempt < 3) {
                usleep(250_000 * $attempt);

                continue;
            }

            return $response;
        }
        throw new RuntimeException('GitHub API request exhausted retry attempts.');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function mapIssue(IntegrationRepository $repository, array $item): ?array
    {
        $id = $item['id'] ?? null;
        $number = $item['number'] ?? null;
        $title = $item['title'] ?? null;
        $state = $item['state'] ?? null;
        $url = $item['html_url'] ?? null;
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;
        if (! is_numeric($id) || ! is_numeric($number) || ! is_string($title) || trim($title) === ''
            || ! in_array($state, ['open', 'closed'], true) || ! is_string($url)
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'github.com'
            || ! is_string($path) || strcasecmp($path, '/'.$repository->full_name.'/issues/'.$number) !== 0) {
            return null;
        }
        $labels = [];
        foreach (is_array($item['labels'] ?? null) ? $item['labels'] : [] as $label) {
            if (is_array($label) && is_string($label['name'] ?? null)) {
                $labels[] = Str::limit(strip_tags($label['name']), 100, '');
            }
        }
        $assignees = [];
        foreach (is_array($item['assignees'] ?? null) ? $item['assignees'] : [] as $assignee) {
            if (is_array($assignee) && is_string($assignee['login'] ?? null)) {
                $assignees[] = Str::limit($assignee['login'], 255, '');
            }
        }
        $author = $item['user']['login'] ?? null;

        return [
            'repository' => $repository->full_name, 'external_id' => (string) $id, 'number' => (int) $number,
            'title' => Str::limit(strip_tags($title), 500, ''), 'state' => $state,
            'labels' => array_slice(array_values(array_unique($labels)), 0, 30),
            'assignees' => array_slice(array_values(array_unique($assignees)), 0, 100),
            'author_login' => is_string($author) ? Str::limit($author, 255, '') : null,
            'github_created_at' => $item['created_at'] ?? null, 'github_updated_at' => $item['updated_at'] ?? null,
            'github_closed_at' => $item['closed_at'] ?? null, 'github_deleted_at' => null, 'url' => $url, 'last_synced_at' => now(),
        ];
    }

    public function refreshIssue(IntegrationRepository $repository, int $issueNumber): void
    {
        $repository->loadMissing('connection');
        if ($repository->status !== IntegrationRepository::STATUS_ACTIVE || $repository->enterprise_id === null
            || $repository->connection === null || ! $repository->connection->isOperational()) {
            return;
        }
        $this->registry->assertOperation('source_control', 'github', 'repository.execute');
        $token = $this->credentials->resolveAccessToken($repository->connection);
        $response = Http::baseUrl('https://api.github.com')->withToken($token)->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'CR8OR'])
            ->timeout((int) config('services.github.timeout', 15))
            ->get('/repos/'.$repository->full_name.'/issues/'.$issueNumber);
        if ($response->status() === 404) {
            $repositoryResponse = Http::baseUrl('https://api.github.com')->withToken($token)->acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'CR8OR'])
                ->timeout((int) config('services.github.timeout', 15))
                ->get('/repos/'.$repository->full_name);
            if ($repositoryResponse->status() === 404 || $repositoryResponse->status() === 403) {
                $repository->forceFill(['status' => IntegrationRepository::STATUS_INACCESSIBLE])->save();

                return;
            }
            if (! $repositoryResponse->successful()) {
                throw new RuntimeException('GitHub repository verification failed with HTTP '.$repositoryResponse->status().'.');
            }
            Issue::query()->where('integration_repository_id', $repository->id)->where('number', $issueNumber)
                ->update(['github_deleted_at' => now(), 'last_synced_at' => now()]);

            return;
        }
        if (! $response->successful()) {
            throw new RuntimeException('GitHub issue refresh failed with HTTP '.$response->status().'.');
        }
        $item = $response->json();
        if (! is_array($item) || isset($item['pull_request'])) {
            return;
        }
        $mapped = $this->mapIssue($repository, $item);
        if ($mapped !== null) {
            $this->persistIssue($repository, $mapped);
        }
    }

    private function syncLegacyRepository(Enterprise $enterprise): int
    {
        $repository = trim((string) $enterprise->github_repository);
        if ($repository === '' || ! preg_match('~^[A-Za-z0-9-]{1,39}/[A-Za-z0-9_.-]{1,100}$~', $repository)) {
            throw new RuntimeException('Enterprise has no valid configured or assigned GitHub repository.');
        }
        $connection = IntegrationConnection::query()->where('organization_id', $enterprise->organization_id)
            ->where('provider', 'github')->where('status', IntegrationConnection::STATUS_ACTIVE)
            ->where(fn ($query) => $query->where('enterprise_id', $enterprise->id)->orWhereNull('enterprise_id'))
            ->orderByRaw('CASE WHEN enterprise_id = ? THEN 0 ELSE 1 END', [$enterprise->id])->first();
        if ($connection === null) {
            throw new RuntimeException('No active GitHub integration connection is configured for this Enterprise or organization.');
        }
        $this->registry->assertOperation('source_control', 'github', 'repository.execute');
        $token = $this->credentials->resolveAccessToken($connection);
        $client = Http::baseUrl('https://api.github.com')->withToken($token)->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'CR8OR'])->timeout((int) config('services.github.timeout', 15));
        $itemsToSync = [];
        for ($page = 1; $page <= 100; $page++) {
            $response = $this->requestPage($client, '/repos/'.$repository.'/issues', ['state' => 'all', 'per_page' => 100, 'page' => $page, 'sort' => 'updated', 'direction' => 'desc']);
            if (! $response->successful()) {
                throw new RuntimeException('GitHub issue synchronization failed with HTTP '.$response->status().'.');
            }
            $items = $response->json();
            if (! is_array($items) || ! array_is_list($items)) {
                throw new RuntimeException('GitHub returned an invalid issue page.');
            }
            foreach ($items as $item) {
                if (! is_array($item) || isset($item['pull_request'])) {
                    continue;
                }
                $mapped = $this->mapLegacyIssue($repository, $item);
                if ($mapped !== null) {
                    $itemsToSync[] = $mapped;
                }
            }
            if (count($items) < 100) {
                DB::transaction(function () use ($enterprise, $repository, $itemsToSync): void {
                    foreach ($itemsToSync as $item) {
                        Issue::query()->updateOrCreate(['enterprise_id' => $enterprise->id, 'repository' => $repository, 'external_id' => $item['external_id']], $item);
                    }
                });
                $enterprise->forceFill(['github_issues_sync_status' => 'succeeded', 'github_issues_synced_at' => now(), 'github_issues_sync_error' => null])->save();

                return count($itemsToSync);
            }
        }
        throw new RuntimeException('GitHub issue synchronization exceeded the 100-page safety limit.');
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function mapLegacyIssue(string $repository, array $item): ?array
    {
        $id = $item['id'] ?? null;
        $number = $item['number'] ?? null;
        $title = $item['title'] ?? null;
        $url = $item['html_url'] ?? null;
        if (! is_numeric($id) || ! is_numeric($number) || ! is_string($title) || ! is_string($url)
            || ! in_array($item['state'] ?? null, ['open', 'closed'], true)
            || parse_url($url, PHP_URL_HOST) !== 'github.com' || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || strcasecmp((string) parse_url($url, PHP_URL_PATH), '/'.$repository.'/issues/'.$number) !== 0) {
            return null;
        }
        $labels = [];
        foreach (is_array($item['labels'] ?? null) ? $item['labels'] : [] as $label) {
            if (is_array($label) && is_string($label['name'] ?? null)) {
                $labels[] = Str::limit(strip_tags($label['name']), 100, '');
            }
        }
        $labels = array_slice(array_values(array_unique($labels)), 0, 30);
        $assignees = [];
        foreach (is_array($item['assignees'] ?? null) ? $item['assignees'] : [] as $assignee) {
            if (is_array($assignee) && is_string($assignee['login'] ?? null)) {
                $assignees[] = Str::limit($assignee['login'], 255, '');
            }
        }
        $assignees = array_slice(array_values(array_unique($assignees)), 0, 100);

        return ['repository' => $repository, 'external_id' => (string) $id, 'number' => (int) $number, 'title' => Str::limit(strip_tags($title), 500, ''), 'state' => $item['state'], 'labels' => $labels, 'assignees' => $assignees, 'author_login' => is_string($item['user']['login'] ?? null) ? Str::limit($item['user']['login'], 255, '') : null, 'github_created_at' => $item['created_at'] ?? null, 'github_updated_at' => $item['updated_at'] ?? null, 'github_closed_at' => $item['closed_at'] ?? null, 'github_deleted_at' => null, 'url' => $url, 'last_synced_at' => now()];
    }
}
