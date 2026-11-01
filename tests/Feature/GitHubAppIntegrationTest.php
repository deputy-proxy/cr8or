<?php

use App\Jobs\DiscoverGitHubRepositories;
use App\Jobs\ProcessGitHubWebhookDelivery;
use App\Jobs\SyncEnterpriseGitHubIssues as SyncEnterpriseGitHubIssuesJob;
use App\Jobs\SyncIntegrationRepositoryIssues;
use App\Models\Enterprise;
use App\Models\GitHubWebhookDelivery;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRepository;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Providers\GitHubCredentialResolver;
use App\Services\GitHubIssueSyncService;
use App\Services\GitHubRepositoryDiscoveryService;
use App\Services\GitHubWebhookHandler;
use App\Services\GitHubWebhookIngestionService;
use App\Services\IntegrationRepositoryAssignmentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;

function githubAppTestKey(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($key, $privateKey);

    return $privateKey;
}

function githubAppTestConnection(Organization $organization, ?Enterprise $enterprise = null, array $metadata = []): IntegrationConnection
{
    return IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise?->id,
        'provider' => 'github',
        'external_account_id' => 'github-account-1',
        'credential_reference' => 'github-app-installation',
        'github_installation_id' => 987654,
        'metadata' => $metadata,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
}

function githubAppTokenResponse(): array
{
    return ['token' => 'ghs-installation-token', 'expires_at' => now()->addHour()->toIso8601String(), 'permissions' => ['issues' => 'read', 'metadata' => 'read']];
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    config(['services.github.app_id' => '12345', 'services.github.private_key' => githubAppTestKey(), 'services.github.webhook_secret' => 'test-webhook-secret']);
});

it('generates and caches short-lived GitHub App installation tokens without persisting them', function () {
    Http::fake(['api.github.com/app/installations/*/access_tokens' => Http::response(githubAppTokenResponse(), 201)]);
    $organization = Organization::factory()->create();
    $connection = githubAppTestConnection($organization);
    $resolver = app(GitHubCredentialResolver::class);

    expect($resolver->resolveAccessToken($connection))->toBe('ghs-installation-token')
        ->and($resolver->resolveAccessToken($connection))->toBe('ghs-installation-token');

    Http::assertSentCount(1);
    expect($connection->fresh()->metadata)->not->toHaveKey('access_token')
        ->and($connection->fresh()->getAttributes())->not->toHaveKey('token');
});

it('refuses to use suspended or revoked GitHub installations', function () {
    $organization = Organization::factory()->create();
    $connection = githubAppTestConnection($organization, metadata: ['installation_suspended_at' => now()->toIso8601String()]);

    expect(fn () => app(GitHubCredentialResolver::class)->resolveAccessToken($connection))->toThrow(RuntimeException::class);

    $connection->forceFill(['metadata' => ['installation_deleted_at' => now()->toIso8601String()]])->save();
    expect(fn () => app(GitHubCredentialResolver::class)->resolveAccessToken($connection->fresh()))->toThrow(RuntimeException::class);
});

it('discovers only repositories returned by the active installation', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(githubAppTokenResponse(), 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => [[
            'id' => 456, 'name' => 'app', 'full_name' => 'acme/app', 'html_url' => 'https://github.com/acme/app',
            'default_branch' => 'main', 'private' => true, 'owner' => ['id' => 99, 'login' => 'acme'],
        ]]], 200),
    ]);
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $connection = githubAppTestConnection($organization);

    $repositories = app(GitHubRepositoryDiscoveryService::class)->discover($connection, $user);

    expect($repositories)->toHaveCount(1)
        ->and($repositories[0]->github_repository_id)->toBe(456)
        ->and($repositories[0]->full_name)->toBe('acme/app')
        ->and($repositories[0]->is_private)->toBeTrue()
        ->and($repositories[0]->enterprise_id)->toBeNull();
});

it('enforces organization and connection boundaries when assigning repositories', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'github_repository' => 'acme/app',
        'github_repository_url' => 'https://github.com/acme/app',
    ]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);
    $connection = githubAppTestConnection($organization);
    $repository = IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id,
        'github_repository_id' => 456, 'owner_login' => 'acme', 'name' => 'app', 'full_name' => 'acme/app',
        'url' => 'https://github.com/acme/app', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);
    $assignment = app(IntegrationRepositoryAssignmentService::class);

    expect($assignment->assign($user, $repository, $enterprise)->enterprise_id)->toBe($enterprise->id);
    $snapshot = $enterprise->issues()->create([
        'integration_repository_id' => $repository->id, 'github_repository_id' => 456, 'repository' => 'acme/app',
        'external_id' => 'ownership-issue-1', 'number' => 9, 'title' => 'Transfer with repository', 'state' => 'open',
        'url' => 'https://github.com/acme/app/issues/9', 'last_synced_at' => now(),
    ]);
    expect(fn () => $assignment->assign($user, $repository->fresh(), $otherEnterprise))->toThrow(LogicException::class);
    $unassigned = $assignment->unassign($user, $repository->fresh());
    expect($unassigned->enterprise_id)->toBeNull()
        ->and($enterprise->fresh()->github_repository)->toBeNull()
        ->and($assignment->assign($user, $unassigned, $otherEnterprise)->enterprise_id)->toBe($otherEnterprise->id)
        ->and($snapshot->fresh()->enterprise_id)->toBe($otherEnterprise->id);
    expect(fn () => $assignment->assign($user, $repository->fresh(), $foreignEnterprise))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('syncs issues for an assigned repository including assignees and excludes pull requests', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(githubAppTokenResponse(), 201),
        'api.github.com/repos/acme/app/issues*' => Http::response([
            [
                'id' => 111, 'number' => 7, 'title' => 'Fix the thing', 'state' => 'open',
                'html_url' => 'https://github.com/acme/app/issues/7', 'created_at' => now()->subDay()->toIso8601String(),
                'updated_at' => now()->toIso8601String(), 'closed_at' => null,
                'labels' => [['name' => 'bug']], 'assignees' => [['login' => 'octocat']], 'user' => ['login' => 'maintainer'],
            ],
            ['id' => 112, 'number' => 8, 'pull_request' => ['url' => 'https://api.github.com/repos/acme/app/pulls/8']],
        ], 200),
    ]);
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection = githubAppTestConnection($organization);
    $repository = IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id, 'enterprise_id' => $enterprise->id,
        'github_repository_id' => 456, 'owner_login' => 'acme', 'name' => 'app', 'full_name' => 'acme/app',
        'url' => 'https://github.com/acme/app', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);

    expect(app(GitHubIssueSyncService::class)->syncRepository($repository))->toBe(1);

    $issue = $enterprise->issues()->sole();
    expect($issue->title)->toBe('Fix the thing')
        ->and($issue->assignees)->toBe(['octocat'])
        ->and($issue->labels)->toBe(['bug'])
        ->and($issue->integration_repository_id)->toBe($repository->id)
        ->and($repository->fresh()->sync_status)->toBe('succeeded');
});

it('verifies GitHub signatures and makes delivery ingestion idempotent', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    githubAppTestConnection($organization);
    $raw = json_encode(['action' => 'opened', 'installation' => ['id' => 987654], 'repository' => ['id' => 456]], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $raw, 'test-webhook-secret');
    $ingestion = app(GitHubWebhookIngestionService::class);

    $first = $ingestion->ingest($raw, $signature, 'delivery-123', 'issues');
    $second = $ingestion->ingest($raw, $signature, 'delivery-123', 'issues');

    expect($first->is($second))->toBeTrue();
    Queue::assertPushed(ProcessGitHubWebhookDelivery::class, 1);
    expect(fn () => $ingestion->ingest($raw, 'sha256='.str_repeat('0', 64), 'delivery-124', 'issues'))->toThrow(RuntimeException::class);
});

it('processes installation suspension and reactivation without bypassing lifecycle methods', function () {
    $organization = Organization::factory()->create();
    $connection = githubAppTestConnection($organization);
    $suspend = GitHubWebhookDelivery::query()->create([
        'delivery_id' => 'suspend-123', 'event_type' => 'installation', 'action' => 'suspend',
        'installation_id' => 987654, 'integration_connection_id' => $connection->id,
        'payload_hash' => hash('sha256', 'suspend'), 'payload' => [], 'status' => 'received', 'received_at' => now(),
    ]);
    app(GitHubWebhookHandler::class)->handle($suspend);
    expect($connection->fresh()->status)->toBe(IntegrationConnection::STATUS_DISABLED)
        ->and($connection->fresh()->metadata['installation_suspended_at'])->not->toBeNull();

    $unsuspend = GitHubWebhookDelivery::query()->create([
        'delivery_id' => 'unsuspend-123', 'event_type' => 'installation', 'action' => 'unsuspend',
        'installation_id' => 987654, 'integration_connection_id' => $connection->id,
        'payload_hash' => hash('sha256', 'unsuspend'), 'payload' => [], 'status' => 'received', 'received_at' => now(),
    ]);
    app(GitHubWebhookHandler::class)->handle($unsuspend);
    expect($connection->fresh()->status)->toBe(IntegrationConnection::STATUS_ACTIVE)
        ->and($connection->fresh()->metadata['installation_suspended_at'])->toBeNull();
});

it('refreshes an installation token before its expiry', function () {
    Http::fakeSequence('api.github.com/app/installations/*/access_tokens')
        ->push(['token' => 'ghs-first', 'expires_at' => now()->addHour()->toIso8601String(), 'permissions' => ['issues' => 'read', 'metadata' => 'read']], 201)
        ->push(['token' => 'ghs-second', 'expires_at' => now()->addHours(2)->toIso8601String(), 'permissions' => ['issues' => 'read', 'metadata' => 'read']], 201);
    $connection = githubAppTestConnection(Organization::factory()->create());
    $resolver = app(GitHubCredentialResolver::class);

    expect($resolver->resolveAccessToken($connection))->toBe('ghs-first');
    $this->travel(56)->minutes();
    try {
        expect($resolver->resolveAccessToken($connection))->toBe('ghs-second');
        Http::assertSentCount(2);
    } finally {
        $this->travelBack();
    }
});

it('preserves snapshots when installation repository access is removed', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection = githubAppTestConnection($organization);
    $repository = IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id, 'enterprise_id' => $enterprise->id,
        'github_repository_id' => 457, 'owner_login' => 'acme', 'name' => 'app', 'full_name' => 'acme/app',
        'url' => 'https://github.com/acme/app', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);
    $issue = $enterprise->issues()->create([
        'integration_repository_id' => $repository->id, 'github_repository_id' => 457, 'repository' => 'acme/app',
        'external_id' => 'issue-1', 'number' => 1, 'title' => 'Keep history', 'state' => 'open',
        'url' => 'https://github.com/acme/app/issues/1', 'last_synced_at' => now(),
    ]);
    $delivery = GitHubWebhookDelivery::query()->create([
        'delivery_id' => 'repo-removed-123', 'event_type' => 'installation_repositories', 'action' => 'removed',
        'installation_id' => 987654, 'integration_connection_id' => $connection->id,
        'payload_hash' => hash('sha256', 'repo-removed'), 'payload' => ['repositories_removed' => [['id' => 457]]],
        'status' => 'received', 'received_at' => now(),
    ]);

    app(GitHubWebhookHandler::class)->handle($delivery);

    expect($repository->fresh()->status)->toBe(IntegrationRepository::STATUS_INACCESSIBLE)
        ->and($issue->fresh())->not->toBeNull()
        ->and($issue->fresh()->title)->toBe('Keep history');
});

it('marks a snapshot deleted only after GitHub confirms the issue is missing', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(githubAppTokenResponse(), 201),
        'api.github.com/repos/acme/app/issues/7' => Http::response([], 404),
        'api.github.com/repos/acme/app' => Http::response(['id' => 458, 'full_name' => 'acme/app'], 200),
    ]);
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection = githubAppTestConnection($organization);
    $repository = IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id, 'enterprise_id' => $enterprise->id,
        'github_repository_id' => 458, 'owner_login' => 'acme', 'name' => 'app', 'full_name' => 'acme/app',
        'url' => 'https://github.com/acme/app', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);
    $issue = $enterprise->issues()->create([
        'integration_repository_id' => $repository->id, 'github_repository_id' => 458, 'repository' => 'acme/app',
        'external_id' => 'issue-7', 'number' => 7, 'title' => 'Deleted issue', 'state' => 'open',
        'url' => 'https://github.com/acme/app/issues/7', 'last_synced_at' => now(),
    ]);

    app(GitHubIssueSyncService::class)->refreshIssue($repository, 7);

    expect($issue->fresh()->github_deleted_at)->not->toBeNull()
        ->and($issue->fresh()->state)->toBe('open');
});

it('syncs multiple assigned repositories independently for one Enterprise', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(githubAppTokenResponse(), 201),
        'api.github.com/repos/acme/one/issues*' => Http::response([[
            'id' => 201, 'number' => 1, 'title' => 'First', 'state' => 'open', 'html_url' => 'https://github.com/acme/one/issues/1',
            'updated_at' => now()->toIso8601String(), 'labels' => [], 'assignees' => [], 'user' => ['login' => 'a'],
        ]], 200),
        'api.github.com/repos/acme/two/issues*' => Http::response([[
            'id' => 202, 'number' => 2, 'title' => 'Second', 'state' => 'closed', 'html_url' => 'https://github.com/acme/two/issues/2',
            'updated_at' => now()->toIso8601String(), 'labels' => [], 'assignees' => [], 'user' => ['login' => 'b'],
        ]], 200),
    ]);
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection = githubAppTestConnection($organization);
    foreach ([['acme/one', 459], ['acme/two', 460]] as [$fullName, $repositoryId]) {
        [$owner, $name] = explode('/', $fullName);
        IntegrationRepository::query()->create([
            'integration_connection_id' => $connection->id, 'organization_id' => $organization->id, 'enterprise_id' => $enterprise->id,
            'github_repository_id' => $repositoryId, 'owner_login' => $owner, 'name' => $name, 'full_name' => $fullName,
            'url' => 'https://github.com/'.$fullName, 'status' => IntegrationRepository::STATUS_ACTIVE,
        ]);
    }

    expect(app(GitHubIssueSyncService::class)->sync($enterprise))->toBe(2)
        ->and($enterprise->issues()->count())->toBe(2)
        ->and($enterprise->fresh()->github_issues_sync_status)->toBe('succeeded');
});

it('does not reactivate an installation that an administrator had already disabled', function () {
    $organization = Organization::factory()->create();
    $connection = githubAppTestConnection($organization);
    $connection->transitionTo(IntegrationConnection::STATUS_DISABLED)->save();
    foreach (['suspend' => 'suspend-manual-123', 'unsuspend' => 'unsuspend-manual-123'] as $action => $deliveryId) {
        $delivery = GitHubWebhookDelivery::query()->create([
            'delivery_id' => $deliveryId, 'event_type' => 'installation', 'action' => $action,
            'installation_id' => 987654, 'integration_connection_id' => $connection->id,
            'payload_hash' => hash('sha256', $deliveryId), 'payload' => [], 'status' => 'received', 'received_at' => now(),
        ]);
        app(GitHubWebhookHandler::class)->handle($delivery);
    }

    expect($connection->fresh()->status)->toBe(IntegrationConnection::STATUS_DISABLED);
});

it('requires reauthorization when an installation loses read access to issues', function () {
    $organization = Organization::factory()->create();
    $connection = githubAppTestConnection($organization);
    $delivery = GitHubWebhookDelivery::query()->create([
        'delivery_id' => 'permissions-changed-123', 'event_type' => 'installation', 'action' => 'new_permissions_accepted',
        'installation_id' => 987654, 'integration_connection_id' => $connection->id,
        'payload_hash' => hash('sha256', 'permissions-changed'), 'payload' => ['permissions' => ['issues' => 'write', 'metadata' => 'read']],
        'status' => 'received', 'received_at' => now(),
    ]);

    app(GitHubWebhookHandler::class)->handle($delivery);

    expect($connection->fresh()->status)->toBe(IntegrationConnection::STATUS_RECONNECT_REQUIRED)
        ->and($connection->fresh()->metadata['github_permissions']['issues'])->toBe('write');

    $restored = GitHubWebhookDelivery::query()->create([
        'delivery_id' => 'permissions-restored-123', 'event_type' => 'installation', 'action' => 'new_permissions_accepted',
        'installation_id' => 987654, 'integration_connection_id' => $connection->id,
        'payload_hash' => hash('sha256', 'permissions-restored'), 'payload' => ['permissions' => ['issues' => 'read', 'metadata' => 'read']],
        'status' => 'received', 'received_at' => now(),
    ]);
    app(GitHubWebhookHandler::class)->handle($restored);

    expect($connection->fresh()->status)->toBe(IntegrationConnection::STATUS_ACTIVE);
});

it('migrates legacy Enterprise repository settings by resolving stable GitHub IDs', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(githubAppTokenResponse(), 201),
        'api.github.com/repos/acme/legacy' => Http::response([
            'id' => 777, 'name' => 'legacy', 'full_name' => 'acme/legacy',
            'html_url' => 'https://github.com/acme/legacy', 'default_branch' => 'main',
            'private' => true, 'owner' => ['id' => 77, 'login' => 'acme'],
        ], 200),
    ]);
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'github_repository' => 'acme/legacy',
        'github_repository_url' => 'https://github.com/acme/legacy',
    ]);
    $connection = githubAppTestConnection($organization);
    IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id, 'enterprise_id' => $enterprise->id,
        'github_repository_id' => 776, 'owner_login' => 'acme', 'name' => 'current', 'full_name' => 'acme/current',
        'url' => 'https://github.com/acme/current', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);
    $issue = $enterprise->issues()->create([
        'repository' => 'acme/legacy', 'external_id' => 'legacy-issue-1', 'number' => 1,
        'title' => 'Preserve this issue', 'state' => 'open', 'url' => 'https://github.com/acme/legacy/issues/1',
        'last_synced_at' => now(),
    ]);

    $result = app(\App\Services\GitHubLegacyRepositoryMigrationService::class)->migrate();

    $repository = IntegrationRepository::query()->where('github_repository_id', 777)->sole();
    expect($result['migrated'])->toBe(1)
        ->and($result['unresolved'])->toBe([])
        ->and($repository->enterprise_id)->toBe($enterprise->id)
        ->and($repository->integration_connection_id)->toBe($connection->id)
        ->and($issue->fresh()->integration_repository_id)->toBe($repository->id)
        ->and($issue->fresh()->github_repository_id)->toBe(777);
});

it('does not fall back to a legacy repository after it has been discovered but left unassigned', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'github_repository' => 'acme/app',
        'github_repository_url' => 'https://github.com/acme/app',
    ]);
    $connection = githubAppTestConnection($organization);
    IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id,
        'github_repository_id' => 999, 'owner_login' => 'acme', 'name' => 'app', 'full_name' => 'acme/app',
        'url' => 'https://github.com/acme/app', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);

    expect(app(GitHubIssueSyncService::class)->sync($enterprise))->toBe(0)
        ->and($enterprise->fresh()->github_issues_sync_status)->toBe('skipped');
    Http::assertNothingSent();
});

it('acknowledges GitHub App ping deliveries without requiring an installation', function () {
    Queue::fake();
    $raw = json_encode(['zen' => 'Keep it logically awesome'], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $raw, 'test-webhook-secret');

    $delivery = app(GitHubWebhookIngestionService::class)->ingest($raw, $signature, 'ping-123', 'ping');

    expect($delivery->status)->toBe('processed')
        ->and($delivery->installation_id)->toBeNull()
        ->and($delivery->payload)->toBe([]);
    Queue::assertNothingPushed();
});

it('keeps the GitHub installation identity immutable after connection creation', function () {
    $connection = githubAppTestConnection(Organization::factory()->create());

    expect(fn () => $connection->forceFill(['github_installation_id' => 987655])->save())->toThrow(LogicException::class);
});

it('prevents an Enterprise-scoped connection from serving another Enterprise', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection = githubAppTestConnection($organization, $enterprise);

    expect(fn () => IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id, 'enterprise_id' => $otherEnterprise->id,
        'github_repository_id' => 9991, 'owner_login' => 'acme', 'name' => 'app', 'full_name' => 'acme/app',
        'url' => 'https://github.com/acme/app', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]))->toThrow(LogicException::class);
});

it('rejects issue snapshots that cross Enterprise repository ownership', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection = githubAppTestConnection($organization);
    $repository = IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id, 'enterprise_id' => $enterprise->id,
        'github_repository_id' => 9992, 'owner_login' => 'acme', 'name' => 'app', 'full_name' => 'acme/app',
        'url' => 'https://github.com/acme/app', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);

    expect(fn () => $otherEnterprise->issues()->create([
        'integration_repository_id' => $repository->id, 'github_repository_id' => $repository->github_repository_id,
        'repository' => $repository->full_name, 'external_id' => 'cross-tenant-issue', 'number' => 1,
        'title' => 'Invalid association', 'state' => 'open', 'url' => 'https://github.com/acme/app/issues/1',
    ]))->toThrow(LogicException::class);
});

it('schedules repository jobs without duplicating Enterprises that still need legacy migration', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    $connection = githubAppTestConnection($organization);

    $repositoryOnlyEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $repositoryOnly = IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id,
        'enterprise_id' => $repositoryOnlyEnterprise->id, 'github_repository_id' => 991,
        'owner_login' => 'acme', 'name' => 'only', 'full_name' => 'acme/only',
        'url' => 'https://github.com/acme/only', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);

    $legacyEnterprise = Enterprise::factory()->create([
        'organization_id' => $organization->id, 'github_repository' => 'acme/legacy',
        'github_repository_url' => 'https://github.com/acme/legacy',
    ]);
    $legacyAssigned = IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id,
        'enterprise_id' => $legacyEnterprise->id, 'github_repository_id' => 992,
        'owner_login' => 'acme', 'name' => 'assigned', 'full_name' => 'acme/assigned',
        'url' => 'https://github.com/acme/assigned', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);

    $discoveredLegacyEnterprise = Enterprise::factory()->create([
        'organization_id' => $organization->id, 'github_repository' => 'acme/discovered',
        'github_repository_url' => 'https://github.com/acme/discovered',
    ]);
    IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id,
        'github_repository_id' => 993, 'owner_login' => 'acme', 'name' => 'discovered', 'full_name' => 'acme/discovered',
        'url' => 'https://github.com/acme/discovered', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);

    Artisan::call('enterprise-portfolio:sync-github-issues');

    Queue::assertPushed(DiscoverGitHubRepositories::class, fn (DiscoverGitHubRepositories $job): bool => $job->connectionId === $connection->id);
    Queue::assertPushed(SyncIntegrationRepositoryIssues::class, fn (SyncIntegrationRepositoryIssues $job): bool => $job->repositoryId === $repositoryOnly->id);
    Queue::assertNotPushed(SyncIntegrationRepositoryIssues::class, fn (SyncIntegrationRepositoryIssues $job): bool => $job->repositoryId === $legacyAssigned->id);
    Queue::assertPushed(SyncEnterpriseGitHubIssuesJob::class, fn (SyncEnterpriseGitHubIssuesJob $job): bool => $job->enterpriseId === $legacyEnterprise->id);
    Queue::assertNotPushed(SyncEnterpriseGitHubIssuesJob::class, fn (SyncEnterpriseGitHubIssuesJob $job): bool => $job->enterpriseId === $discoveredLegacyEnterprise->id);
});

it('exposes the native GitHub webhook endpoint with raw-body signature verification', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    githubAppTestConnection($organization);
    $raw = json_encode(['action' => 'opened', 'installation' => ['id' => 987654], 'repository' => ['id' => 456], 'issue' => ['number' => 1]], JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $raw, 'test-webhook-secret');

    $response = $this->call('POST', '/integrations/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'HTTP_X_GITHUB_DELIVERY' => 'route-delivery-123',
        'HTTP_X_GITHUB_EVENT' => 'issues',
    ], $raw);

    $response->assertAccepted()->assertJsonPath('delivery_id', 'route-delivery-123');
    Queue::assertPushed(ProcessGitHubWebhookDelivery::class, 1);

    $invalidResponse = $this->call('POST', '/integrations/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.str_repeat('0', 64),
        'HTTP_X_GITHUB_DELIVERY' => 'route-delivery-124',
        'HTTP_X_GITHUB_EVENT' => 'issues',
    ], $raw);
    $invalidResponse->assertUnauthorized();
    expect(GitHubWebhookDelivery::query()->count())->toBe(1);
});

it('queues synchronization for assigned repositories after scheduled discovery', function () {
    Queue::fake();
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(githubAppTokenResponse(), 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => [[
            'id' => 994, 'name' => 'app', 'full_name' => 'acme/app', 'html_url' => 'https://github.com/acme/app',
            'default_branch' => 'main', 'private' => false, 'updated_at' => now()->toIso8601String(),
            'owner' => ['id' => 99, 'login' => 'acme'],
        ]]], 200),
    ]);
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection = githubAppTestConnection($organization);
    $repository = IntegrationRepository::query()->create([
        'integration_connection_id' => $connection->id, 'organization_id' => $organization->id, 'enterprise_id' => $enterprise->id,
        'github_repository_id' => 994, 'owner_login' => 'acme', 'name' => 'app', 'full_name' => 'acme/app',
        'url' => 'https://github.com/acme/app', 'status' => IntegrationRepository::STATUS_ACTIVE,
    ]);

    (new DiscoverGitHubRepositories((int) $connection->id))->handle(app(GitHubRepositoryDiscoveryService::class));

    Queue::assertPushed(SyncIntegrationRepositoryIssues::class, fn (SyncIntegrationRepositoryIssues $job): bool => $job->repositoryId === $repository->id);
});
