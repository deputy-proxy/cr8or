<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRepository;
use App\Models\Issue;
use App\Providers\GitHubCredentialResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GitHubLegacyRepositoryMigrationService
{
    public function __construct(
        private readonly GitHubCredentialResolver $credentials,
        private readonly IntegrationRegistry $registry,
    ) {}

    /** @return array{migrated: int, skipped: int, unresolved: array<string>} */
    public function migrate(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'unresolved' => []];
        Enterprise::query()->whereNotNull('github_repository')->orderBy('id')->chunkById(100, function ($enterprises) use (&$result): void {
            foreach ($enterprises as $enterprise) {
                $legacyName = trim((string) $enterprise->github_repository);
                if ($legacyName === '') {
                    $result['skipped']++;

                    continue;
                }
                if (! preg_match('~^[A-Za-z0-9-]{1,39}/[A-Za-z0-9_.-]{1,100}$~', $legacyName)) {
                    $result['unresolved'][] = $enterprise->name.' (invalid legacy repository identifier)';

                    continue;
                }
                if ($enterprise->integrationRepositories()->whereRaw('LOWER(full_name) = ?', [strtolower($legacyName)])->exists()) {
                    $result['skipped']++;

                    continue;
                }

                $connection = IntegrationConnection::query()->where('organization_id', $enterprise->organization_id)
                    ->where('provider', 'github')->where('status', IntegrationConnection::STATUS_ACTIVE)
                    ->where(fn ($query) => $query->where('enterprise_id', $enterprise->id)->orWhereNull('enterprise_id'))
                    ->orderByRaw('CASE WHEN enterprise_id = ? THEN 0 ELSE 1 END', [$enterprise->id])->first();
                if ($connection === null) {
                    $result['unresolved'][] = $enterprise->name.' (no active GitHub connection)';

                    continue;
                }

                try {
                    $this->registry->assertOperation('source_control', 'github', 'repository.execute');
                    $token = $this->credentials->resolveAccessToken($connection);
                    $response = Http::baseUrl('https://api.github.com')->withToken($token)->acceptJson()
                        ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'CR8OR'])
                        ->timeout((int) config('services.github.timeout', 15))->get('/repos/'.$legacyName);
                    if (! $response->successful()) {
                        throw new RuntimeException('GitHub returned HTTP '.$response->status());
                    }
                    $data = $response->json();
                    if (! is_array($data) || ! is_numeric($data['id'] ?? null) || (int) $data['id'] < 1 || ! is_string($data['full_name'] ?? null)
                        || ! is_string($data['html_url'] ?? null) || ! is_array($data['owner'] ?? null)
                        || ! is_string($data['owner']['login'] ?? null) || ! is_string($data['name'] ?? null)
                        || strcasecmp($data['full_name'], $data['owner']['login'].'/'.$data['name']) !== 0
                        || parse_url($data['html_url'], PHP_URL_SCHEME) !== 'https'
                        || parse_url($data['html_url'], PHP_URL_HOST) !== 'github.com'
                        || strcasecmp((string) parse_url($data['html_url'], PHP_URL_PATH), '/'.$data['full_name']) !== 0) {
                        throw new RuntimeException('GitHub returned incomplete repository identity data.');
                    }
                    $repositoryId = (int) $data['id'];
                    $existing = IntegrationRepository::query()->where('github_repository_id', $repositoryId)->first();
                    if ($existing !== null && (int) $existing->integration_connection_id !== (int) $connection->id) {
                        throw new RuntimeException('Repository is already linked to a different integration connection.');
                    }
                    DB::transaction(function () use ($enterprise, $connection, $data, $repositoryId, $legacyName): void {
                        $repository = IntegrationRepository::query()->updateOrCreate(['github_repository_id' => $repositoryId], [
                            'integration_connection_id' => $connection->id,
                            'organization_id' => $enterprise->organization_id,
                            'enterprise_id' => $enterprise->id,
                            'github_account_id' => is_numeric($data['owner']['id'] ?? null) ? (int) $data['owner']['id'] : null,
                            'owner_login' => $data['owner']['login'],
                            'name' => $data['name'],
                            'full_name' => $data['full_name'],
                            'url' => $data['html_url'],
                            'default_branch' => is_string($data['default_branch'] ?? null) ? $data['default_branch'] : null,
                            'is_private' => (bool) ($data['private'] ?? false),
                            'status' => IntegrationRepository::STATUS_ACTIVE,
                            'github_updated_at' => is_string($data['updated_at'] ?? null) ? $data['updated_at'] : now(),
                        ]);
                        Issue::query()->where('enterprise_id', $enterprise->id)->whereRaw('LOWER(repository) = ?', [strtolower($legacyName)])->update([
                            'integration_repository_id' => $repository->id,
                            'github_repository_id' => $repositoryId,
                        ]);
                        $enterprise->forceFill([
                            'github_repository' => $data['full_name'],
                            'github_repository_url' => $data['html_url'],
                        ])->save();
                    });
                    $result['migrated']++;
                } catch (\Throwable $exception) {
                    $result['unresolved'][] = $enterprise->name.' ('.$legacyName.'): '.$exception->getMessage();
                }
            }
        });

        return $result;
    }
}
