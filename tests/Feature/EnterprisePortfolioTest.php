<?php

use App\Filament\Pages\EnterprisePortfolioDashboard;
use App\Filament\Resources\Enterprises\Pages\EditEnterprise;
use App\Jobs\SyncEnterpriseGitHubIssues;
use App\Models\Enterprise;
use App\Models\EnterpriseCategory;
use App\Models\EnterpriseGroup;
use App\Models\Event;
use App\Models\IntegrationConnection;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\GitHubIssueSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('persists organization-owned taxonomy and validates all Enterprise connection directions', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $group = EnterpriseGroup::factory()->create(['organization_id' => $organization->id]);
    $category = EnterpriseCategory::factory()->create(['organization_id' => $organization->id]);
    $source = Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_group_id' => $group->id,
        'enterprise_category_id' => $category->id,
    ]);
    $target = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $foreignTarget = Enterprise::factory()->create(['organization_id' => $otherOrganization->id]);

    $connections = [
        ['target_enterprise_id' => $target->id, 'type' => 'supports', 'direction' => 'incoming', 'description' => 'Incoming edge'],
        ['target_enterprise_id' => $target->id, 'type' => 'integrates_with', 'direction' => 'outgoing', 'description' => 'Outgoing edge'],
        ['target_enterprise_id' => $target->id, 'type' => 'related_to', 'direction' => 'bidirectional', 'description' => 'Two-way edge'],
    ];
    $source->forceFill(['connections' => $connections])->save();

    expect($source->fresh()->group->is($group))->toBeTrue()
        ->and($source->fresh()->category->is($category))->toBeTrue()
        ->and($source->fresh()->connections)->toHaveCount(3);

    expect(fn () => $source->forceFill(['connections' => [[
        'target_enterprise_id' => $foreignTarget->id,
        'type' => 'supports',
        'direction' => 'outgoing',
    ]]])->save())->toThrow(LogicException::class);

    expect(fn () => $source->forceFill(['connections' => [[
        'target_enterprise_id' => $source->id,
        'type' => 'related_to',
        'direction' => 'outgoing',
    ]]])->save())->toThrow(LogicException::class);

    expect(fn () => $source->forceFill(['connections' => [[
        'target_enterprise_id' => $target->id,
        'type' => 'made_up_type',
        'direction' => 'outgoing',
    ]]])->save())->toThrow(LogicException::class);
});

it('prevents taxonomy ownership changes and cross-organization category assignments', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $group = EnterpriseGroup::factory()->create(['organization_id' => $organization->id]);
    $category = EnterpriseCategory::factory()->create(['organization_id' => $otherOrganization->id]);

    expect(fn () => $group->forceFill(['organization_id' => $otherOrganization->id])->save())
        ->toThrow(LogicException::class);

    expect(fn () => Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_category_id' => $category->id,
    ]))->toThrow(LogicException::class);
});

it('rejects non-canonical GitHub repository URLs', function () {
    expect(fn () => Enterprise::factory()->create([
        'github_repository' => 'owner/repository',
        'github_repository_url' => 'https://github.com:8443/owner/repository',
    ]))->toThrow(LogicException::class);

    expect(fn () => Enterprise::factory()->create([
        'github_repository' => 'owner/repository',
        'github_repository_url' => 'https://github.com/owner/repository?redirect=example.com',
    ]))->toThrow(LogicException::class);
});

it('uses a pure black background for the Project Ecosystem Map', function () {
    $css = file_get_contents(resource_path('css/enterprise-portfolio.css'));

    expect($css)->toContain('background: #000000;')
        ->and($css)->not->toContain('#08080a');
});

it('renders only authorized Enterprises on the portfolio dashboard', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);

    Enterprise::factory()->create(['organization_id' => $organization->id, 'name' => 'Visible portfolio enterprise']);
    Enterprise::factory()->create(['organization_id' => $foreignOrganization->id, 'name' => 'Foreign portfolio enterprise']);

    $this->actingAs($user)
        ->get(route('filament.admin.pages.enterprise-portfolio-dashboard'))
        ->assertOk()
        ->assertSee('All Groups')
        ->assertSee('dot-grid')
        ->assertDontSee('bg-[#08080a]')
        ->assertSee("const excluded = ['project_id', 'stream_id', 'name', 'description', 'enterprise_id', 'website_domain', 'github_repository', 'projects_count', 'tasks_count', 'work_items_count', 'content_items_count'];", false)
        ->assertDontSee('tailwindcss.com')
        ->assertDontSee('cdn.jsdelivr.net/npm/alpinejs')
        ->assertSee('Visible portfolio enterprise')
        ->assertDontSee('Foreign portfolio enterprise');
});

it('hydrates and saves typed Enterprise connections through the Filament form', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $source = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $target = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $source->forceFill(['connections' => [[
        'target_enterprise_id' => $target->id,
        'type' => 'supports',
        'direction' => 'bidirectional',
        'description' => 'Shared platform',
    ]]])->save();

    $this->actingAs($user);
    $component = Livewire::test(EditEnterprise::class, ['record' => $source->getRouteKey()]);
    $state = $component->get('data.connections');
    expect($state)->toHaveCount(1);
    $key = array_key_first($state);
    expect($state[$key]['type'])->toBe('connection')
        ->and($state[$key]['data'])->toMatchArray([
            'target_enterprise_id' => $target->id,
            'type' => 'supports',
            'direction' => 'bidirectional',
            'description' => 'Shared platform',
        ]);

    $component->fillForm([
        'connections' => [
            $key => [
                'type' => 'connection',
                'data' => [
                    'target_enterprise_id' => $target->id,
                    'type' => 'owns',
                    'direction' => 'outgoing',
                    'description' => 'Updated from Filament',
                ],
            ],
        ],
    ])->call('save')->assertHasNoFormErrors();

    expect($source->fresh()->connections)->toBe([[
        'target_enterprise_id' => $target->id,
        'type' => 'owns',
        'direction' => 'outgoing',
        'description' => 'Updated from Filament',
    ]]);
});

it('renders the Project Ecosystem Map from authorized Enterprise records and connections', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $source = Enterprise::factory()->create(['organization_id' => $organization->id, 'name' => 'Source map enterprise']);
    $target = Enterprise::factory()->create(['organization_id' => $organization->id, 'name' => 'Target map enterprise']);
    $source->forceFill(['connections' => [[
        'target_enterprise_id' => $target->id,
        'type' => 'supports',
        'direction' => 'outgoing',
        'description' => 'Map relationship',
    ]]])->save();

    $this->actingAs($user)
        ->get(route('filament.admin.pages.enterprise-portfolio-dashboard'))
        ->assertOk()
        ->assertSee('Source map enterprise')
        ->assertSee('Target map enterprise')
        ->assertSee('supports')
        ->assertSee('arrow-supports')
        ->assertDontSee('co.nt.ro')
        ->assertDontSee('prop-001');
});

it('rejects selecting an Enterprise outside the user authorized organizations', function () {
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $visible = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $hidden = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);

    $this->actingAs($user);
    $page = app(EnterprisePortfolioDashboard::class);
    $page->mount();

    expect(fn () => $page->selectEnterprise((int) $hidden->id))->toThrow(NotFoundHttpException::class);
    $page->selectEnterprise((int) $visible->id);
    expect($page->selectedEnterpriseId)->toBe((int) $visible->id);
});

it('ingests allowlisted GTM events idempotently using the request Origin', function () {
    $enterprise = Enterprise::factory()->create(['website_domain' => 'example.com']);
    $eventId = 'b30d6bb6-1c65-4b57-9e2a-86f5e9c9dd17';
    $payload = [
        'event_id' => $eventId,
        'event_type' => 'page_view',
        'occurred_at' => now()->subMinute()->toISOString(),
        'payload' => ['page_path' => '/pricing', 'page_title' => 'Pricing', 'campaign_source' => 'newsletter'],
    ];

    $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/api/events/website', $payload)->assertStatus(202);
    $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/api/events/website', $payload)->assertStatus(202);

    $event = Event::query()->where('enterprise_id', $enterprise->id)->where('source', Event::SOURCE_GTM)->where('source_event_id', $eventId)->first();
    expect(Event::query()->where('enterprise_id', $enterprise->id)->where('source', Event::SOURCE_GTM)->where('source_event_id', $eventId)->count())->toBe(1)
        ->and($event?->payload)->toMatchArray(['page_path' => '/pricing', 'page_title' => 'Pricing', 'campaign_source' => 'newsletter'])
        ->and($event?->organization_id)->toBe((int) $enterprise->organization_id);
});

it('rejects unconfigured website origins, arbitrary payload fields, and stale timestamps', function () {
    Enterprise::factory()->create(['website_domain' => 'example.com']);
    $base = [
        'event_id' => 'b30d6bb6-1c65-4b57-9e2a-86f5e9c9dd18',
        'event_type' => 'page_view',
        'occurred_at' => now()->subMinute()->toISOString(),
        'payload' => ['page_path' => '/pricing'],
    ];

    $this->withHeaders(['Origin' => 'https://attacker.example'])->postJson('/api/events/website', $base)->assertNotFound();
    $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/api/events/website', array_replace($base, [
        'event_id' => 'b30d6bb6-1c65-4b57-9e2a-86f5e9c9dd19',
        'payload' => ['page_path' => '/pricing', 'enterprise_id' => 123],
    ]))->assertUnprocessable();
    $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/api/events/website', array_replace($base, [
        'event_id' => 'b30d6bb6-1c65-4b57-9e2a-86f5e9c9dd20',
        'occurred_at' => now()->subDays(31)->toISOString(),
    ]))->assertUnprocessable();
    $this->withHeaders(['Origin' => 'http://example.com'])->postJson('/api/events/website', $base)->assertBadRequest();
});

it('records Enterprise lifecycle activity as immutable, idempotent feed entries', function () {
    $enterprise = Enterprise::factory()->create();
    $event = Event::query()->where('enterprise_id', $enterprise->id)->where('source', Event::SOURCE_CR8OR)->first();

    expect($event)->not->toBeNull()
        ->and($event?->description)->toContain($enterprise->name);

    expect(fn () => $event?->forceFill(['description' => 'Tampered'])->save())
        ->toThrow(LogicException::class);
});

it('removes credentials, reasoning, and personal-data keys from CR8OR activity payloads', function () {
    $enterprise = Enterprise::factory()->create();
    $event = app(\App\Services\EnterpriseEventRecorder::class)->record(
        organizationId: (int) $enterprise->organization_id,
        enterpriseId: (int) $enterprise->id,
        source: Event::SOURCE_CR8OR,
        eventType: 'operation_executed',
        description: 'Operation executed',
        occurredAt: \Illuminate\Support\Carbon::now(),
        payload: [
            'operation' => 'content.create',
            'access_token' => 'must-not-persist',
            'reasoning' => 'private internal reasoning',
            'email' => 'person@example.com',
            'nested' => ['password' => 'secret', 'safe_value' => 'retained'],
        ],
        sourceEventId: 'portfolio-sanitizer-test',
    );

    expect($event->payload)->toBe([
        'operation' => 'content.create',
        'nested' => ['safe_value' => 'retained'],
    ]);
});

it('supports preflight requests only for configured HTTPS origins and rejects oversized payloads', function () {
    Enterprise::factory()->create(['website_domain' => 'example.com']);

    $this->call('OPTIONS', '/api/events/website', [], [], [], ['HTTP_ORIGIN' => 'https://example.com'])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'https://example.com')
        ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');

    $this->withHeaders(['Origin' => 'https://example.com'])->postJson('/api/events/website', [
        'event_id' => 'b30d6bb6-1c65-4b57-9e2a-86f5e9c9dd21',
        'event_type' => 'page_view',
        'occurred_at' => now()->subMinute()->toISOString(),
        'payload' => ['page_title' => str_repeat('x', 9000)],
    ])->assertStatus(413);
});

it('exposes taxonomy write authorization only for organizations the user manages', function () {
    $managed = Organization::factory()->create();
    $foreign = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $managed->id]);

    expect(\Illuminate\Support\Facades\Gate::forUser($user)->allows('createForOrganization', [EnterpriseGroup::class, $managed]))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($user)->allows('createForOrganization', [EnterpriseGroup::class, $foreign]))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Gate::forUser($user)->allows('createForOrganization', [EnterpriseCategory::class, $foreign]))->toBeFalse();
});

it('synchronizes GitHub issue snapshots idempotently and excludes pull requests', function () {
    config(['services.github.credentials.default' => 'test-token']);
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'github_repository' => 'owner/repository',
        'github_repository_url' => 'https://github.com/owner/repository',
    ]);
    IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'github',
        'external_account_id' => 'owner/repository',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
        'configuration' => [],
        'metadata' => [],
    ]);

    $issue = [
        'id' => 800001,
        'number' => 12,
        'title' => 'Track portfolio dashboard',
        'state' => 'open',
        'labels' => [['name' => 'enhancement']],
        'user' => ['login' => 'maintainer'],
        'created_at' => '2026-10-01T10:00:00Z',
        'updated_at' => '2026-10-10T10:00:00Z',
        'closed_at' => null,
        'html_url' => 'https://github.com/owner/repository/issues/12',
    ];
    $pullRequest = [
        'id' => 800002,
        'number' => 13,
        'title' => 'A pull request',
        'state' => 'open',
        'pull_request' => ['url' => 'https://api.github.com/repos/owner/repository/pulls/13'],
        'html_url' => 'https://github.com/owner/repository/pulls/13',
    ];
    Http::fakeSequence()->push([$issue, $pullRequest], 200)->push([$issue, $pullRequest], 200);

    $sync = app(GitHubIssueSyncService::class);
    expect($sync->sync($enterprise))->toBe(1)
        ->and($sync->sync($enterprise->fresh()))->toBe(1)
        ->and(Issue::query()->where('enterprise_id', $enterprise->id)->count())->toBe(1)
        ->and(Issue::query()->where('enterprise_id', $enterprise->id)->first()?->labels)->toBe(['enhancement'])
        ->and($enterprise->fresh()->github_issues_sync_status)->toBe('succeeded');
});

it('renders incoming, outgoing, and bidirectional connections with their persisted semantics', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $source = Enterprise::factory()->create(['organization_id' => $organization->id, 'name' => 'Source Enterprise']);
    $target = Enterprise::factory()->create(['organization_id' => $organization->id, 'name' => 'Target Enterprise']);

    $source->forceFill(['connections' => [
        ['target_enterprise_id' => $target->id, 'type' => 'supports', 'direction' => 'incoming', 'description' => 'Incoming edge'],
        ['target_enterprise_id' => $target->id, 'type' => 'owns', 'direction' => 'outgoing', 'description' => 'Outgoing edge'],
        ['target_enterprise_id' => $target->id, 'type' => 'related_to', 'direction' => 'bidirectional', 'description' => 'Two-way edge'],
    ]])->save();

    $this->actingAs($user);
    $page = app(EnterprisePortfolioDashboard::class);
    $page->mount();
    $method = new ReflectionMethod($page, 'getViewData');
    $method->setAccessible(true);
    $data = $method->invoke($page);
    $links = collect($data['graphData']['links']);

    expect($links->firstWhere('type', 'supports'))->toMatchArray([
        'source' => 'enterprise:'.$target->id,
        'target' => 'enterprise:'.$source->id,
    ])->and($links->firstWhere('type', 'owns'))->toMatchArray([
        'source' => 'enterprise:'.$source->id,
        'target' => 'enterprise:'.$target->id,
    ])->and($links->where('type', 'related_to')->pluck('source')->all())->toContain(
        'enterprise:'.$source->id,
        'enterprise:'.$target->id,
    );
});

it('paginates GitHub issues and commits snapshots only after the final page', function () {
    config(['services.github.credentials.default' => 'test-token']);
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'github_repository' => 'owner/repository',
        'github_repository_url' => 'https://github.com/owner/repository',
    ]);
    IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'github',
        'external_account_id' => 'owner/repository',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
        'configuration' => [],
        'metadata' => [],
    ]);

    $firstPage = [];
    for ($number = 1; $number <= 99; $number++) {
        $firstPage[] = [
            'id' => 910000 + $number,
            'number' => $number,
            'title' => 'Issue '.$number,
            'state' => 'open',
            'labels' => [],
            'user' => ['login' => 'maintainer'],
            'created_at' => '2026-10-01T10:00:00Z',
            'updated_at' => '2026-10-10T10:00:00Z',
            'closed_at' => null,
            'html_url' => 'https://github.com/owner/repository/issues/'.$number,
        ];
    }
    $firstPage[] = [
        'id' => 919999,
        'number' => 100,
        'title' => 'Pull request is excluded',
        'state' => 'open',
        'pull_request' => ['url' => 'https://api.github.com/repos/owner/repository/pulls/100'],
        'html_url' => 'https://github.com/owner/repository/pulls/100',
    ];
    $secondPage = [[
        'id' => 910101,
        'number' => 101,
        'title' => 'Last issue on page two',
        'state' => 'closed',
        'labels' => [],
        'user' => ['login' => 'maintainer'],
        'created_at' => '2026-10-01T10:00:00Z',
        'updated_at' => '2026-10-10T10:00:00Z',
        'closed_at' => '2026-10-10T10:00:00Z',
        'html_url' => 'https://github.com/owner/repository/issues/101',
    ]];
    Http::fakeSequence()->push($firstPage, 200)->push($secondPage, 200);

    expect(app(GitHubIssueSyncService::class)->sync($enterprise))->toBe(100)
        ->and(Issue::query()->where('enterprise_id', $enterprise->id)->count())->toBe(100)
        ->and(Issue::query()->where('enterprise_id', $enterprise->id)->where('number', 100)->exists())->toBeFalse()
        ->and(Issue::query()->where('enterprise_id', $enterprise->id)->where('number', 101)->exists())->toBeTrue()
        ->and(Http::recorded()->count())->toBe(2);
});

it('preserves GitHub snapshots when synchronization fails', function () {
    config(['services.github.credentials.default' => 'test-token']);
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'github_repository' => 'owner/repository',
        'github_repository_url' => 'https://github.com/owner/repository',
    ]);
    IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'provider' => 'github',
        'external_account_id' => 'owner/repository',
        'credential_reference' => 'default',
        'status' => IntegrationConnection::STATUS_ACTIVE,
        'configuration' => [],
        'metadata' => [],
    ]);
    $existing = Issue::factory()->create(['enterprise_id' => $enterprise->id, 'repository' => 'owner/repository', 'external_id' => '987654', 'number' => 12]);
    Http::fake(['api.github.com/*' => Http::response(['message' => 'unavailable'], 503)]);

    expect(fn () => app(GitHubIssueSyncService::class)->sync($enterprise))->toThrow(RuntimeException::class);
    expect(Issue::query()->whereKey($existing->id)->exists())->toBeTrue()
        ->and($enterprise->fresh()->github_issues_sync_status)->toBe('failed')
        ->and($enterprise->fresh()->github_issues_sync_error)->not->toBeEmpty();
});

it('queues GitHub sync only through the authorized dashboard action', function () {
    Queue::fake();
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);
    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->id,
        'github_repository' => 'owner/repository',
        'github_repository_url' => 'https://github.com/owner/repository',
    ]);

    $this->actingAs($user);
    $page = app(EnterprisePortfolioDashboard::class);
    $page->mount();
    $page->selectEnterprise((int) $enterprise->id);
    $page->syncGitHubIssues();

    Queue::assertPushed(SyncEnterpriseGitHubIssues::class, fn (SyncEnterpriseGitHubIssues $job): bool => $job->enterpriseId === (int) $enterprise->id);
});
