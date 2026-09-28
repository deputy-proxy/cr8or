<?php

use App\Contracts\PlatformEvent;
use App\Data\Integrations\IntegrationEvent;
use App\Data\Integrations\IntegrationWebhook;
use App\Events\AgentExecutionStarted;
use App\Events\DomainEvent;
use App\Events\StrategicContextVersionPublished;
use App\Models\AgentExecution;
use App\Models\Enterprise;
use App\Models\Organization;
use App\Services\AgentExecutionEventService;
use Illuminate\Support\Carbon;

it('classifies platform events without replacing authoritative state', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);

    $domain = new StrategicContextVersionPublished(
        organizationId: $organization->id,
        enterpriseId: $enterprise->id,
        actorId: null,
        correlationId: 'corr-1',
        data: ['record_type' => 'vision', 'version' => 2],
    );

    $integration = new IntegrationEvent(
        eventType: 'publication.submitted',
        organizationId: $organization->id,
        enterpriseId: $enterprise->id,
        integration: 'publishing',
        provider: 'postiz',
        direction: IntegrationEvent::DIRECTION_OUTBOUND,
        correlationId: 'corr-2',
        causationId: $domain->eventId(),
        schemaVersion: '1.0',
        occurredAt: Carbon::now(),
        payload: ['external_job_id' => 'job-1'],
    );

    $webhook = new IntegrationWebhook(
        integration: 'publishing',
        provider: 'postiz',
        eventType: 'publication.completed',
        externalId: 'job-1',
        correlationId: 'corr-2',
        causationId: $integration->eventId(),
        deliveryId: 'delivery-1',
        payload: ['status' => 'succeeded'],
    );

    expect($domain)->toBeInstanceOf(PlatformEvent::class)
        ->and($domain)->toBeInstanceOf(DomainEvent::class)
        ->and($domain->category())->toBe('domain')
        ->and($integration->category())->toBe('integration')
        ->and($integration->metadata()['direction'])->toBe('outbound')
        ->and($webhook->category())->toBe('external_webhook')
        ->and($webhook->eventId())->toBe('delivery-1')
        ->and($webhook->metadata()['causation_id'])->toBe($integration->eventId());
});

it('keeps platform event versions and causal metadata explicit', function () {
    $event = new IntegrationEvent(
        eventType: 'design.created',
        organizationId: 1,
        enterpriseId: 2,
        integration: 'creative',
        provider: 'canva',
        direction: IntegrationEvent::DIRECTION_INBOUND,
        correlationId: 'corr-3',
        causationId: 'cause-1',
        schemaVersion: '1.0',
        occurredAt: Carbon::parse('2026-09-28T07:00:00Z'),
        payload: ['provider_status' => 'completed'],
    );

    expect($event->version())->toBe(1)
        ->and($event->metadata())->toMatchArray([
            'correlation_id' => 'corr-3',
            'causation_id' => 'cause-1',
            'schema_version' => '1.0',
        ]);
});

it('sanitizes model reasoning and prompt-shaped fields from durable event payloads', function () {
    $event = new IntegrationEvent(
        eventType: 'model.completed',
        organizationId: 1,
        enterpriseId: 2,
        integration: 'media',
        provider: 'cr8or-media',
        direction: IntegrationEvent::DIRECTION_OUTBOUND,
        correlationId: 'corr-4',
        causationId: null,
        schemaVersion: '1.0',
        occurredAt: Carbon::now(),
        payload: [
            'result_id' => 'result-1',
            'analysis' => 'private reasoning',
            'prompt' => 'private prompt',
            'nested' => ['model_output' => 'hidden', 'status' => 'ok'],
        ],
    );

    expect($event->payload())->toBe([
        'result_id' => 'result-1',
        'nested' => ['status' => 'ok'],
    ]);
});

it('keeps Agent execution events in the broader platform taxonomy and idempotent audit stream', function () {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $execution = AgentExecution::factory()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterprise->id,
        'correlation_id' => 'corr-agent',
    ]);

    $service = app(AgentExecutionEventService::class);
    $eventClass = AgentExecutionStarted::class;

    $event = new $eventClass(
        executionId: $execution->id,
        enterpriseId: $enterprise->id,
        agentAssignmentId: $execution->agent_assignment_id,
        agentSlug: $execution->agent_slug,
        actorId: $execution->actor_id,
        correlationId: $execution->correlation_id,
        causationId: null,
        provenance: ['source' => 'test'],
        data: ['status' => 'started'],
        eventId: 'event-fixed',
    );

    event($event);
    event($event);

    expect($event->category())->toBe('agent_execution')
        ->and($event->metadata()['causation_id'])->toBeNull()
        ->and($event->eventId())->toBe('event-fixed');
});