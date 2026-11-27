<?php

use App\Capabilities\CapabilityRegistry;
use App\Enums\CapabilityExecutionMode;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateDecisionTool;
use App\Mcp\Tools\GetDecisionTool;
use App\Mcp\Tools\ListDecisionsTool;
use App\Mcp\Tools\UpdateDecisionTool;
use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Operations\DecisionCreate;
use App\Operations\DecisionGet;
use App\Operations\DecisionList;
use App\Operations\DecisionUpdate;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;

function decisionMcpActor(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['name' => 'MCP Decision Actor']);
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);

    return [$user, $enterprise, $organization];
}

it('registers four governed Decision tools with explicit human-only capability mappings', function (): void {
    [$user] = decisionMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');
    $server->tools()->assertRegistered([
        ListDecisionsTool::class,
        GetDecisionTool::class,
        CreateDecisionTool::class,
        UpdateDecisionTool::class,
    ]);

    $registry = app(CapabilityRegistry::class);
    $mappings = [
        ListDecisionsTool::class => ['decision.list', DecisionList::class],
        GetDecisionTool::class => ['decision.get', DecisionGet::class],
        CreateDecisionTool::class => ['decision.create', DecisionCreate::class],
        UpdateDecisionTool::class => ['decision.update', DecisionUpdate::class],
    ];

    foreach ($mappings as $tool => [$capability, $operation]) {
        $definition = $registry->forTool($tool);
        expect($definition->key)->toBe($capability)
            ->and($registry->operationForTool($tool))->toBeInstanceOf($operation)
            ->and($definition->executionModes)->toBe([CapabilityExecutionMode::HUMAN]);
    }
});

it('creates, lists, retrieves and updates a Decision through governed MCP operations', function (): void {
    [$user, $enterprise] = decisionMcpActor();
    $server = Cr8orServer::actingAs($user, 'api');
    $before = now();

    $server->tool(CreateDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'strategic',
        'title' => 'Enter the German market',
        'summary' => 'Launch a controlled market-entry pilot.',
        'rationale' => 'Demand signals justify a limited experiment.',
    ])->assertOk()
        ->assertSee('Enter the German market')
        ->assertSee('MCP Decision Actor');

    $decision = Decision::query()->where('enterprise_id', $enterprise->getKey())->sole();

    expect($decision->actor_id)->toBe($user->getKey())
        ->and($decision->actor_name)->toBe('MCP Decision Actor')
        ->and($decision->type)->toBe('strategic')
        ->and($decision->decided_at->greaterThanOrEqualTo($before->copy()->startOfSecond()))->toBeTrue();

    $server->tool(ListDecisionsTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'strategic',
        'search' => 'German',
        'per_page' => 10,
        'page' => 1,
    ])->assertOk()
        ->assertSee('Enter the German market')
        ->assertSee('"total":1');

    $server->tool(GetDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'decision_id' => $decision->getKey(),
    ])->assertOk()
        ->assertSee('Demand signals justify a limited experiment.');

    $originalDecidedAt = $decision->decided_at->toISOString();
    $server->tool(UpdateDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'decision_id' => $decision->getKey(),
        'title' => 'Enter Germany with a pilot',
        'rationale' => 'Updated evidence supports a controlled pilot.',
        'type' => 'operational',
        'actor_id' => 999999,
        'actor_name' => 'Forged actor',
        'decided_at' => Carbon::parse('2001-01-01')->toISOString(),
    ])->assertOk()
        ->assertSee('Enter Germany with a pilot');

    $decision->refresh();
    expect($decision->title)->toBe('Enter Germany with a pilot')
        ->and($decision->summary)->toBe('Launch a controlled market-entry pilot.')
        ->and($decision->rationale)->toBe('Updated evidence supports a controlled pilot.')
        ->and($decision->type)->toBe('strategic')
        ->and($decision->actor_id)->toBe($user->getKey())
        ->and($decision->actor_name)->toBe('MCP Decision Actor')
        ->and($decision->decided_at->toISOString())->toBe($originalDecidedAt)
        ->and($decision->enterprise_id)->toBe($enterprise->getKey());
});

it('does not disclose or mutate Decisions across Enterprise organization boundaries', function (): void {
    [$user, $enterprise] = decisionMcpActor();
    $foreignEnterprise = Enterprise::factory()->create();
    $foreignDecision = Decision::factory()->create(['enterprise_id' => $foreignEnterprise]);

    $server = Cr8orServer::actingAs($user, 'api');
    $server->tool(ListDecisionsTool::class, [
        'enterprise_id' => $enterprise->getKey(),
    ])->assertOk()
        ->assertDontSee($foreignDecision->title);

    $server->tool(GetDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'decision_id' => $foreignDecision->getKey(),
    ])->assertHasErrors();

    $server->tool(UpdateDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'decision_id' => $foreignDecision->getKey(),
        'title' => 'Cross-tenant mutation',
    ])->assertHasErrors();

    $server->tool(ListDecisionsTool::class, [
        'enterprise_id' => $foreignEnterprise->getKey(),
    ])->assertHasErrors();

    expect($foreignDecision->fresh()->title)->not->toBe('Cross-tenant mutation');
});

it('enforces Decision create and update policies while allowing authorized members to read', function (): void {
    [, $enterprise] = decisionMcpActor();
    $member = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $member,
        'organization_id' => $enterprise->organization_id,
    ]);
    $decision = Decision::factory()->create([
        'enterprise_id' => $enterprise,
        'title' => 'Member-visible decision',
    ]);

    $server = Cr8orServer::actingAs($member, 'api');
    $server->tool(ListDecisionsTool::class, [
        'enterprise_id' => $enterprise->getKey(),
    ])->assertOk()->assertSee('Member-visible decision');

    $server->tool(GetDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'decision_id' => $decision->getKey(),
    ])->assertOk()->assertSee('Member-visible decision');

    $server->tool(CreateDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'title' => 'Unauthorized decision',
        'summary' => 'A regular member must not create this.',
    ])->assertHasErrors();

    $server->tool(UpdateDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'decision_id' => $decision->getKey(),
        'title' => 'Unauthorized update',
    ])->assertHasErrors();

    expect($decision->fresh()->title)->toBe('Member-visible decision');
});

it('rejects invalid Decision types and context references outside the Enterprise', function (): void {
    [$user, $enterprise] = decisionMcpActor();
    $foreignProject = App\Models\Project::factory()->create();

    $server = Cr8orServer::actingAs($user, 'api');
    $server->tool(CreateDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'type' => 'invented',
        'title' => 'Invalid type',
        'summary' => 'Must not be recorded.',
    ])->assertHasErrors();

    $server->tool(CreateDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'project_id' => $foreignProject->getKey(),
        'title' => 'Foreign project reference',
        'summary' => 'Must not be recorded.',
    ])->assertHasErrors();

    expect(Decision::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});

it('rejects empty Decision updates and invalid pagination input', function (): void {
    [$user, $enterprise] = decisionMcpActor();
    $decision = Decision::factory()->create(['enterprise_id' => $enterprise]);

    $server = Cr8orServer::actingAs($user, 'api');
    $server->tool(UpdateDecisionTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'decision_id' => $decision->getKey(),
    ])->assertHasErrors();

    $server->tool(ListDecisionsTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'per_page' => 500,
    ])->assertHasErrors();
});

it('does not register Decision tools for unauthenticated users or users without organization membership', function (): void {
    $tool = app(ListDecisionsTool::class);

    expect($tool->shouldRegister(new Request))->toBeFalse();

    $user = User::factory()->create();
    $this->actingAs($user);

    expect($tool->shouldRegister(new Request))->toBeFalse();
});
