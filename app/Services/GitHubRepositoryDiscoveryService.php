<?php

namespace App\Services;

use App\Models\IntegrationConnection;
use App\Models\IntegrationRepository;
use App\Models\User;
use App\Providers\GitHubCredentialResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GitHubRepositoryDiscoveryService
{
    public function __construct(
        private readonly GitHubCredentialResolver $credentials,
        private readonly IntegrationRegistry $registry,
    ) {}

    /** @return list<IntegrationRepository> */
    public function discover(IntegrationConnection $connection, ?User $actor = null): array
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $connection);
        }
        if ($connection->provider !== 'github' || ! $connection->isOperational() || $connection->github_installation_id === null) {
            throw new RuntimeException('An active GitHub App installation is required for repository discovery.');
        }

        $this->registry->assertOperation('source_control', 'github', 'repository.execute');
        $token = $this->credentials->resolveAccessToken($connection);
        $client = Http::baseUrl('https://api.github.com')->withToken($token)->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'CR8OR'])
            ->timeout((int) config('services.github.timeout', 15));
        $seen = [];

        for ($page = 1; $page <= 100; $page++) {
            $response = $client->get('/installation/repositories', ['per_page' => 100, 'page' => $page]);
            if (! $response->successful()) {
                throw new RuntimeException('GitHub repository discovery failed with HTTP '.$response->status().'.');
            }
            $repositories = $response->json('repositories');
            if (! is_array($repositories) || ! array_is_list($repositories)) {
                throw new RuntimeException('GitHub returned an invalid repository discovery response.');
            }

            foreach ($repositories as $item) {
                if (! is_array($item) || ! is_numeric($item['id'] ?? null) || (int) $item['id'] < 1 || ! is_string($item['full_name'] ?? null)
                    || ! is_string($item['name'] ?? null) || ! is_array($item['owner'] ?? null)
                    || ! is_string($item['owner']['login'] ?? null) || ! is_string($item['html_url'] ?? null)
                    || strcasecmp($item['full_name'], $item['owner']['login'].'/'.$item['name']) !== 0
                    || parse_url($item['html_url'], PHP_URL_SCHEME) !== 'https'
                    || parse_url($item['html_url'], PHP_URL_HOST) !== 'github.com'
                    || strcasecmp((string) parse_url($item['html_url'], PHP_URL_PATH), '/'.$item['full_name']) !== 0) {
                    throw new RuntimeException('GitHub returned an invalid repository identity.');
                }
                $repositoryId = (int) $item['id'];
                $existing = IntegrationRepository::query()->where('github_repository_id', $repositoryId)->first();
                if ($existing !== null && (int) $existing->integration_connection_id !== (int) $connection->id) {
                    throw new RuntimeException('A discovered repository is already linked to a different CR8OR GitHub connection.');
                }
                $seen[] = $repositoryId;
                IntegrationRepository::query()->updateOrCreate(
                    ['github_repository_id' => $repositoryId],
                    [
                        'integration_connection_id' => $connection->id,
                        'organization_id' => $connection->organization_id,
                        'github_account_id' => is_numeric($item['owner']['id'] ?? null) ? (int) $item['owner']['id'] : null,
                        'owner_login' => $item['owner']['login'],
                        'name' => $item['name'],
                        'full_name' => $item['full_name'],
                        'url' => $item['html_url'],
                        'default_branch' => is_string($item['default_branch'] ?? null) ? $item['default_branch'] : null,
                        'is_private' => (bool) ($item['private'] ?? false),
                        'status' => IntegrationRepository::STATUS_ACTIVE,
                        'github_updated_at' => is_string($item['updated_at'] ?? null) ? $item['updated_at'] : now(),
                    ],
                );
            }

            if (count($repositories) < 100) {
                break;
            }
            if ($page === 100) {
                throw new RuntimeException('GitHub repository discovery exceeded the 100-page safety limit.');
            }
        }

        DB::transaction(function () use ($connection, $seen): void {
            IntegrationRepository::query()->where('integration_connection_id', $connection->id)
                ->update(['status' => IntegrationRepository::STATUS_INACCESSIBLE]);
            foreach (array_chunk($seen, 500) as $repositoryIds) {
                IntegrationRepository::query()->where('integration_connection_id', $connection->id)
                    ->whereIn('github_repository_id', $repositoryIds)
                    ->update(['status' => IntegrationRepository::STATUS_ACTIVE]);
            }
            $metadata = $connection->getAttribute('metadata');
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['repositories_last_discovered_at'] = now()->toIso8601String();
            $connection->forceFill(['metadata' => $metadata])->save();
        });

        $discovered = $connection->repositories()->whereIn('github_repository_id', $seen)->orderBy('full_name')->get()->values()->all();

        /** @var list<IntegrationRepository> $discovered */
        return $discovered;
    }
}
