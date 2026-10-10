<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\IntegrationConnection;
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
        $repository = trim((string) $enterprise->github_repository);
        $enterprise->forceFill(['github_issues_sync_status' => 'syncing', 'github_issues_sync_error' => null])->save();

        try {
            if ($repository === '') {
                throw new RuntimeException('Enterprise has no configured GitHub repository.');
            }

            $connection = IntegrationConnection::query()
                ->where('organization_id', $enterprise->organization_id)
                ->where('provider', 'github')
                ->where('status', IntegrationConnection::STATUS_ACTIVE)
                ->where(function ($query) use ($enterprise): void {
                    $query->where('enterprise_id', $enterprise->id)->orWhereNull('enterprise_id');
                })
                ->orderByRaw('CASE WHEN enterprise_id = ? THEN 0 ELSE 1 END', [$enterprise->id])
                ->first();

            if ($connection === null) {
                throw new RuntimeException('No active GitHub integration connection is configured for this Enterprise or organization.');
            }

            $this->registry->assertOperation('source_control', 'github', 'repository.execute');
            $token = $this->credentials->resolveAccessToken($connection);
            $client = Http::baseUrl('https://api.github.com')
                ->withToken($token)
                ->acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout((int) config('services.github.timeout', 15));

            $itemsToSync = [];
            for ($page = 1; $page <= 100; $page++) {
                $response = $this->requestPage($client, $repository, $page);
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

                    $mapped = $this->mapIssue($repository, $item);
                    if ($mapped !== null) {
                        $itemsToSync[] = $mapped;
                    }
                }

                if (count($items) < 100) {
                    DB::transaction(function () use ($enterprise, $repository, $itemsToSync): void {
                        foreach ($itemsToSync as $item) {
                            Issue::query()->updateOrCreate(
                                [
                                    'enterprise_id' => $enterprise->id,
                                    'repository' => $repository,
                                    'external_id' => $item['external_id'],
                                ],
                                $item,
                            );
                        }
                    });

                    $enterprise->forceFill([
                        'github_issues_sync_status' => 'succeeded',
                        'github_issues_synced_at' => now(),
                        'github_issues_sync_error' => null,
                    ])->save();

                    return count($itemsToSync);
                }
            }

            throw new RuntimeException('GitHub issue synchronization exceeded the 100-page safety limit.');
        } catch (Throwable $exception) {
            $enterprise->forceFill([
                'github_issues_sync_status' => 'failed',
                'github_issues_sync_error' => Str::limit($exception->getMessage(), 500, ''),
            ])->save();

            throw $exception;
        }
    }

    private function requestPage(PendingRequest $client, string $repository, int $page): Response
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $client->get('/repos/'.$repository.'/issues', [
                    'state' => 'all',
                    'per_page' => 100,
                    'page' => $page,
                    'sort' => 'updated',
                    'direction' => 'desc',
                ]);
            } catch (ConnectionException $exception) {
                if ($attempt === 3) {
                    throw new RuntimeException('GitHub API connection failed after retry attempts.', previous: $exception);
                }

                usleep(250_000 * $attempt);

                continue;
            }

            $rateLimited = $response->status() === 429
                || ($response->status() === 403 && ($response->header('X-RateLimit-Remaining') === '0' || trim($response->header('Retry-After')) !== ''));

            if ($rateLimited) {
                if ($attempt === 3) {
                    throw new RuntimeException('GitHub API rate limit exceeded after retry attempts.');
                }

                $retryAfter = (int) (trim($response->header('Retry-After')) !== '' ? $response->header('Retry-After') : '1');
                if ($retryAfter > 2) {
                    throw new RuntimeException('GitHub API rate limit exceeded; retry after '.$retryAfter.' seconds.');
                }
                usleep(max(1, $retryAfter) * 1_000_000);

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
    private function mapIssue(string $repository, array $item): ?array
    {
        $externalId = $item['id'] ?? null;
        $number = $item['number'] ?? null;
        $title = $item['title'] ?? null;
        $state = $item['state'] ?? null;
        $url = $item['html_url'] ?? null;
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

        if (! is_numeric($externalId) || ! is_numeric($number) || ! is_string($title) || trim($title) === ''
            || ! in_array($state, ['open', 'closed'], true)
            || ! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'github.com'
            || ! is_string($path) || strcasecmp($path, '/'.$repository.'/issues/'.$number) !== 0) {
            return null;
        }

        $labels = [];
        $rawLabels = $item['labels'] ?? [];
        foreach (is_array($rawLabels) ? $rawLabels : [] as $label) {
            if (is_array($label) && is_string($label['name'] ?? null)) {
                $labels[] = Str::limit(strip_tags($label['name']), 100, '');
            }
        }

        $author = $item['user']['login'] ?? null;

        return [
            'repository' => $repository,
            'external_id' => (string) $externalId,
            'number' => (int) $number,
            'title' => Str::limit(strip_tags($title), 500, ''),
            'state' => $state,
            'labels' => array_slice(array_values(array_unique($labels)), 0, 30),
            'author_login' => is_string($author) ? Str::limit($author, 255, '') : null,
            'github_created_at' => $item['created_at'] ?? null,
            'github_updated_at' => $item['updated_at'] ?? null,
            'github_closed_at' => $item['closed_at'] ?? null,
            'url' => $url,
            'last_synced_at' => now(),
        ];
    }
}
