<?php

use App\Capabilities\CapabilityRegistry;
use App\Enums\TaskStatus;
use App\Experts\CommunicationExpert;
use App\Experts\OperationsExpert;
use App\Mail\CapabilityEmail;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\ListGitHubIssuesTool;
use App\Mcp\Tools\ListTaskTool;
use App\Mcp\Tools\SendEmailTool;
use App\Models\Enterprise;
use App\Models\IntegrationRepository;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Operations\ListGitHubIssues;
use App\Operations\ListTasks;
use App\Operations\SendEmail;
use App\Services\ExpertCapabilityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function digestCapabilityActor(?Organization $organization = null): array
{
    $organization ??= Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    return [$actor, $organization];
}

it('registers the three digest capabilities as unique governed Operations Expert actions', function (): void {
    [$actor] = digestCapabilityActor();
    Cr8orServer::actingAs($actor, 'api')->tools()->assertRegistered([
        ListTaskTool::class,
        ListGitHubIssuesTool::class,
        SendEmailTool::class,
    ]);

    $registry = app(CapabilityRegistry::class);

    expect($registry->forTool(ListTaskTool::class)->key)->toBe('work.task.list')
        ->and($registry->operationForTool(ListTaskTool::class))->toBeInstanceOf(ListTasks::class)
        ->and($registry->forTool(ListGitHubIssuesTool::class)->key)->toBe('integration.github.issue.list')
        ->and($registry->operationForTool(ListGitHubIssuesTool::class))->toBeInstanceOf(ListGitHubIssues::class)
        ->and($registry->forTool(SendEmailTool::class)->key)->toBe('communication.email.send')
        ->and($registry->operationForTool(SendEmailTool::class))->toBeInstanceOf(SendEmail::class)
        ->and($registry->resolve('work.task.list')->category)->toBe('read')
        ->and($registry->resolve('integration.github.issue.list')->category)->toBe('read')
        ->and(app(OperationsExpert::class)->capabilities())->toContain(
            'work.task.list',
            'integration.github.issue.list',
        )
        ->and(app(CommunicationExpert::class)->capabilities())->toContain('communication.email.send')
        ->and(app(ExpertCapabilityResolver::class)->resolve('communication', 'communication.email.send')->operation)->toBe(SendEmail::class);
});

it('lists only open tasks in the selected Enterprise by default and supports pagination', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    $todo = Task::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Prepare weekly digest', 'status' => TaskStatus::Todo]);
    Task::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Work in progress', 'status' => TaskStatus::InProgress]);
    Task::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Completed task', 'status' => TaskStatus::Done]);
    Task::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Cancelled task', 'status' => TaskStatus::Cancelled]);
    Task::factory()->create(['enterprise_id' => $otherEnterprise->getKey(), 'name' => 'Other Enterprise task']);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListTaskTool::class, ['enterprise_id' => $enterprise->getKey(), 'per_page' => 50, 'page' => 1])
        ->assertOk()
        ->assertSee(['Prepare weekly digest', 'Work in progress'])
        ->assertSee('"total":2')
        ->assertSee('"per_page":50')
        ->assertDontSee(['Completed task', 'Cancelled task', 'Other Enterprise task']);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListTaskTool::class, ['enterprise_id' => $enterprise->getKey(), 'status' => TaskStatus::Done->value])
        ->assertOk()
        ->assertSee('Completed task')
        ->assertDontSee(['Prepare weekly digest', 'Cancelled task']);
});

it('denies task listing outside the authenticated actor organization', function (): void {
    [$actor] = digestCapabilityActor();
    $foreignEnterprise = Enterprise::factory()->create();
    Task::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListTaskTool::class, ['enterprise_id' => $foreignEnterprise->getKey()])
        ->assertHasErrors();
});

it('lists only open GitHub issue snapshots for the selected legacy Enterprise repository and exposes sync state', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'github_repository' => 'deputy-proxy/digest-demo',
        'github_repository_url' => 'https://github.com/deputy-proxy/digest-demo',
        'github_issues_sync_status' => 'succeeded',
        'github_issues_synced_at' => now(),
    ]);
    $otherEnterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'github_repository' => 'deputy-proxy/other-demo',
        'github_repository_url' => 'https://github.com/deputy-proxy/other-demo',
        'github_issues_sync_status' => 'succeeded',
        'github_issues_synced_at' => now(),
    ]);

    Issue::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'repository' => 'deputy-proxy/digest-demo',
        'title' => 'Fix digest query',
        'state' => 'open',
    ]);
    Issue::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'repository' => 'deputy-proxy/digest-demo',
        'title' => 'Closed historical issue',
        'state' => 'closed',
    ]);
    Issue::factory()->create([
        'enterprise_id' => $otherEnterprise->getKey(),
        'repository' => 'deputy-proxy/other-demo',
        'title' => 'Other enterprise issue',
        'state' => 'open',
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListGitHubIssuesTool::class, ['enterprise_id' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee('Fix digest query')
        ->assertSee('"availability":"ready"')
        ->assertDontSee(['Closed historical issue', 'Other enterprise issue']);
});

it('does not present an unconfigured GitHub integration as a confirmed empty issue list', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListGitHubIssuesTool::class, ['enterprise_id' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee('"availability":"not_configured"')
        ->assertSee('"total":0');
});

it('denies GitHub issue listing outside the authenticated actor organization', function (): void {
    [$actor] = digestCapabilityActor();
    $foreignEnterprise = Enterprise::factory()->create([
        'github_repository' => 'deputy-proxy/foreign-demo',
        'github_repository_url' => 'https://github.com/deputy-proxy/foreign-demo',
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListGitHubIssuesTool::class, ['enterprise_id' => $foreignEnterprise->getKey()])
        ->assertHasErrors();
});

it('sends email only to the authenticated actor verified email through the configured delivery mailer', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    config(['mail.default' => 'smtp']);
    Mail::fake();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(SendEmailTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'subject' => 'Daily enterprise digest',
            'text_body' => 'Your digest is ready: A & B <C>.',
            'to' => 'attacker@example.com',
        ])
        ->assertOk()
        ->assertSee($actor->email)
        ->assertDontSee('attacker@example.com');

    Mail::assertSent(CapabilityEmail::class, function (CapabilityEmail $mail) use ($actor): bool {
        $mail->assertTo($actor->email);
        $mail->assertSeeInText('A & B <C>');
        $mail->assertSeeInHtml('A & B <C>');

        return true;
    });
});

it('rejects email delivery for an unverified actor and does not send mail', function (): void {
    $organization = Organization::factory()->create();
    $actor = User::factory()->unverified()->create();
    Membership::factory()->owner()->create(['user_id' => $actor->getKey(), 'organization_id' => $organization->getKey()]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    config(['mail.default' => 'smtp']);
    Mail::fake();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(SendEmailTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'subject' => 'Daily enterprise digest',
            'text_body' => 'Your digest is ready.',
        ])
        ->assertHasErrors();

    Mail::assertNothingOutgoing();
});

it('rejects non-delivery log mail transport instead of reporting a false delivery success', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    config(['mail.default' => 'log']);
    Mail::fake();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(SendEmailTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'subject' => 'Daily enterprise digest',
            'text_body' => 'Your digest is ready.',
        ])
        ->assertHasErrors();

    Mail::assertNothingOutgoing();
});

it('reports a failed legacy GitHub synchronization as unavailable rather than as an empty result', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'github_repository' => 'deputy-proxy/failed-sync',
        'github_repository_url' => 'https://github.com/deputy-proxy/failed-sync',
        'github_issues_sync_status' => 'failed',
        'github_issues_sync_error' => 'provider details must not be exposed',
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListGitHubIssuesTool::class, ['enterprise_id' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee('"availability":"unavailable"')
        ->assertSee('"has_error":true')
        ->assertDontSee('provider details must not be exposed');
});

it('publishes a deterministic Workflow containing all three digest capabilities', function (): void {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    \App\Models\ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'operations'],
        ['runtime_class' => OperationsExpert::class, 'enabled' => true],
    );
    \App\Models\ExpertDescriptor::query()->updateOrCreate(
        ['slug' => 'communication'],
        ['runtime_class' => CommunicationExpert::class, 'enabled' => true],
    );
    $workflow = \App\Models\Workflow::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'execution_policy' => ['mode' => 'deterministic'],
    ]);

    foreach ([
        ['key' => 'tasks', 'capability' => 'work.task.list', 'input' => ['defaults' => ['scope' => 'all_accessible'], 'required' => []]],
        ['key' => 'issues', 'capability' => 'integration.github.issue.list', 'input' => ['defaults' => ['scope' => 'all_accessible'], 'required' => []]],
        ['key' => 'email', 'expert' => 'communication', 'capability' => 'communication.email.send', 'input' => ['required' => ['subject', 'text_body']]],
    ] as $index => $definition) {
        \App\Models\WorkflowStage::factory()->create([
            'workflow_id' => $workflow->getKey(),
            'key' => $definition['key'],
            'name' => ucfirst($definition['key']),
            'sequence' => $index + 1,
            'dependencies' => $index === 0 ? [] : [$index === 1 ? 'tasks' : 'issues'],
            'expert_slugs' => [$definition['expert'] ?? 'operations'],
            'capability_slugs' => [$definition['capability']],
            'input_contract' => $definition['input'],
            'output_contract' => ['required' => ['result']],
        ]);
    }

    $version = app(\App\Services\WorkflowVersionService::class)->publish($workflow, $actor, 'enterprise-digest-capabilities');

    expect($version->status)->toBe('published')
        ->and($workflow->refresh()->published_version_id)->toBe($version->getKey())
        ->and($version->stage_definitions)->toHaveCount(3);
});

it('marks old successful GitHub snapshots as stale in the issue listing', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'github_repository' => 'deputy-proxy/stale-snapshots',
        'github_repository_url' => 'https://github.com/deputy-proxy/stale-snapshots',
        'github_issues_sync_status' => 'succeeded',
        'github_issues_synced_at' => now()->subDays(2),
    ]);
    config(['services.github.issue_sync_stale_after_minutes' => 1440]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListGitHubIssuesTool::class, ['enterprise_id' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee('"availability":"partial"')
        ->assertSee('"is_stale":true')
        ->assertSee('issue data is stale');
});

it('lists open issue snapshots only through active GitHub App repository assignments', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $connectionId = DB::table('integration_connections')->insertGetId([
        'organization_id' => $organization->getKey(),
        'enterprise_id' => $enterprise->getKey(),
        'provider' => 'github',
        'external_account_id' => 'installation-'.fake()->unique()->numerify('#####'),
        'credential_reference' => 'secret://github/test-installation',
        'status' => 'active',
        'metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $repository = IntegrationRepository::query()->create([
        'integration_connection_id' => $connectionId,
        'organization_id' => $organization->getKey(),
        'enterprise_id' => $enterprise->getKey(),
        'github_repository_id' => 987654321,
        'owner_login' => 'deputy-proxy',
        'name' => 'active-demo',
        'full_name' => 'deputy-proxy/active-demo',
        'url' => 'https://github.com/deputy-proxy/active-demo',
        'default_branch' => 'main',
        'is_private' => false,
        'status' => IntegrationRepository::STATUS_ACTIVE,
        'sync_status' => 'succeeded',
        'last_synced_at' => now(),
    ]);
    Issue::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'integration_repository_id' => $repository->getKey(),
        'github_repository_id' => $repository->github_repository_id,
        'repository' => $repository->full_name,
        'title' => 'Active repository issue',
        'state' => 'open',
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListGitHubIssuesTool::class, ['enterprise_id' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee('Active repository issue')
        ->assertSee('"availability":"ready"')
        ->assertSee('"included":true');
});

it('returns a generic mail transport failure without exposing provider details', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    config(['mail.default' => 'smtp']);
    Mail::shouldReceive('to')->once()->with($actor->email)->andThrow(new RuntimeException('provider-secret-token'));

    Cr8orServer::actingAs($actor, 'api')
        ->tool(SendEmailTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'subject' => 'Daily enterprise digest',
            'text_body' => 'Your digest is ready.',
        ])
        ->assertHasErrors()
        ->assertDontSee('provider-secret-token');
});

it('lists tasks across every Enterprise the actor may view without crossing organization boundaries', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $anchorEnterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey(), 'name' => 'Anchor Enterprise']);
    $secondEnterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey(), 'name' => 'Second Enterprise']);
    $foreignEnterprise = Enterprise::factory()->create(['name' => 'Foreign Enterprise']);

    Task::factory()->create(['enterprise_id' => $anchorEnterprise->getKey(), 'name' => 'Anchor open task', 'status' => TaskStatus::Todo]);
    Task::factory()->create(['enterprise_id' => $secondEnterprise->getKey(), 'name' => 'Second open task', 'status' => TaskStatus::InProgress]);
    Task::factory()->create(['enterprise_id' => $foreignEnterprise->getKey(), 'name' => 'Foreign open task', 'status' => TaskStatus::Todo]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListTaskTool::class, [
            'enterprise_id' => $anchorEnterprise->getKey(),
            'scope' => 'all_accessible',
            'max_items' => 10,
        ])
        ->assertOk()
        ->assertSee(['Anchor open task', 'Second open task'])
        ->assertSee('"scope":"all_accessible"')
        ->assertSee('"total_open_tasks":2')
        ->assertDontSee('Foreign open task');
});

it('refuses to return a partial all-Enterprise task set when the completeness limit is exceeded', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    Task::factory()->count(2)->create(['enterprise_id' => $enterprise->getKey(), 'status' => TaskStatus::Todo]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListTaskTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'scope' => 'all_accessible',
            'max_items' => 1,
        ])
        ->assertHasErrors();
});

it('lists GitHub issues across all accessible Enterprises with per-Enterprise sync state', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $anchorEnterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Anchor Enterprise',
        'github_repository' => 'deputy-proxy/anchor-repo',
        'github_repository_url' => 'https://github.com/deputy-proxy/anchor-repo',
        'github_issues_sync_status' => 'succeeded',
        'github_issues_synced_at' => now(),
    ]);
    $secondEnterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'name' => 'Second Enterprise',
        'github_repository' => 'deputy-proxy/second-repo',
        'github_repository_url' => 'https://github.com/deputy-proxy/second-repo',
        'github_issues_sync_status' => 'succeeded',
        'github_issues_synced_at' => now(),
    ]);
    $foreignEnterprise = Enterprise::factory()->create([
        'github_repository' => 'deputy-proxy/foreign-repo',
        'github_repository_url' => 'https://github.com/deputy-proxy/foreign-repo',
        'github_issues_sync_status' => 'succeeded',
        'github_issues_synced_at' => now(),
    ]);

    Issue::factory()->create(['enterprise_id' => $anchorEnterprise->getKey(), 'repository' => 'deputy-proxy/anchor-repo', 'title' => 'Anchor issue', 'state' => 'open']);
    Issue::factory()->create(['enterprise_id' => $secondEnterprise->getKey(), 'repository' => 'deputy-proxy/second-repo', 'title' => 'Second issue', 'state' => 'open']);
    Issue::factory()->create(['enterprise_id' => $foreignEnterprise->getKey(), 'repository' => 'deputy-proxy/foreign-repo', 'title' => 'Foreign issue', 'state' => 'open']);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListGitHubIssuesTool::class, [
            'enterprise_id' => $anchorEnterprise->getKey(),
            'scope' => 'all_accessible',
            'max_items' => 10,
        ])
        ->assertOk()
        ->assertSee(['Anchor issue', 'Second issue'])
        ->assertSee('"total_open_issues":2')
        ->assertSee('"source_data_complete":true')
        ->assertDontSee('Foreign issue');
});

it('reports an unconfigured Enterprise when aggregating GitHub issues across accessible Enterprises', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $anchorEnterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'github_repository' => 'deputy-proxy/configured-repo',
        'github_repository_url' => 'https://github.com/deputy-proxy/configured-repo',
        'github_issues_sync_status' => 'succeeded',
        'github_issues_synced_at' => now(),
    ]);
    $unconfiguredEnterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    Issue::factory()->create([
        'enterprise_id' => $anchorEnterprise->getKey(),
        'repository' => 'deputy-proxy/configured-repo',
        'title' => 'Configured repository issue',
        'state' => 'open',
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListGitHubIssuesTool::class, [
            'enterprise_id' => $anchorEnterprise->getKey(),
            'scope' => 'all_accessible',
            'max_items' => 10,
        ])
        ->assertOk()
        ->assertSee('Configured repository issue')
        ->assertSee('"source_data_complete":false')
        ->assertSee('"availability":"not_configured"')
        ->assertSee((string) $unconfiguredEnterprise->getKey());
});

it('refuses to return a partial all-Enterprise GitHub issue set when the completeness limit is exceeded', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
        'github_repository' => 'deputy-proxy/over-limit-repo',
        'github_repository_url' => 'https://github.com/deputy-proxy/over-limit-repo',
        'github_issues_sync_status' => 'succeeded',
        'github_issues_synced_at' => now(),
    ]);
    Issue::factory()->count(2)->create([
        'enterprise_id' => $enterprise->getKey(),
        'repository' => 'deputy-proxy/over-limit-repo',
        'state' => 'open',
    ]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ListGitHubIssuesTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'scope' => 'all_accessible',
            'max_items' => 1,
        ])
        ->assertHasErrors();
});

it('rejects a round-robin mailer that can silently fall back to a non-delivery transport', function (): void {
    [$actor, $organization] = digestCapabilityActor();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    config([
        'mail.default' => 'roundrobin',
        'mail.mailers.roundrobin' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'log']],
        'mail.mailers.smtp' => ['transport' => 'smtp'],
        'mail.mailers.log' => ['transport' => 'log'],
    ]);
    Mail::fake();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(SendEmailTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'subject' => 'Daily enterprise digest',
            'text_body' => 'Your digest is ready.',
        ])
        ->assertHasErrors();

    Mail::assertNothingOutgoing();
});
