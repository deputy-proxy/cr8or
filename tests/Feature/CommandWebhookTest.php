<?php

use App\Models\CommandWebhookDelivery;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use App\Models\WorkItem;

function commandWebhookHeaders(string $secret, string $body, int $timestamp, string $key = 'test-command'): array
{
    return [
        'X-CR8OR-Command-Key' => $key,
        'X-CR8OR-Command-Timestamp' => (string) $timestamp,
        'X-CR8OR-Command-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}

function commandWebhookActor(Enterprise $enterprise): User
{
    $actor = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $actor,
        'organization_id' => $enterprise->organization_id,
    ]);

    return $actor;
}

it('authenticates a command webhook and executes only an allowlisted business Capability', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = commandWebhookActor($enterprise);
    $item = WorkItem::factory()->create([
        'enterprise_id' => $enterprise,
        'name' => 'Before',
    ]);

    config()->set('services.command_webhooks.credentials.test-command', [
        'secret' => 'command-secret',
        'actor_id' => $actor->getKey(),
        'capabilities' => ['work.item.update'],
    ]);

    $payload = [
        'enterprise_slug' => $enterprise->slug,
        'idempotency_key' => 'command-success-1',
        'correlation_id' => 'command-correlation-1',
        'input' => [
            'work_item_id' => $item->getKey(),
            'name' => 'After',
        ],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $response = $this->withHeaders(commandWebhookHeaders('command-secret', $body, now()->timestamp))
        ->postJson('/commands/work.item.update', $payload);

    $response->assertOk()
        ->assertJsonPath('status', 'executed')
        ->assertJsonPath('capability', 'work.item.update')
        ->assertJsonPath('provenance.correlation_id', 'command-correlation-1');

    expect($item->refresh()->name)->toBe('After')
        ->and(CommandWebhookDelivery::query()->count())->toBe(1)
        ->and(CommandWebhookDelivery::query()->first()->status)->toBe(CommandWebhookDelivery::STATUS_SUCCEEDED);
});

it('rejects an invalid command webhook signature before business execution', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = commandWebhookActor($enterprise);
    $item = WorkItem::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Before']);

    config()->set('services.command_webhooks.credentials.test-command', [
        'secret' => 'command-secret',
        'actor_id' => $actor->getKey(),
        'capabilities' => ['work.item.update'],
    ]);

    $payload = [
        'enterprise_slug' => $enterprise->slug,
        'idempotency_key' => 'command-invalid-signature',
        'correlation_id' => 'command-invalid-signature-correlation',
        'input' => ['work_item_id' => $item->getKey(), 'name' => 'Should not happen'],
    ];

    $response = $this->withHeaders(commandWebhookHeaders('wrong-secret', json_encode($payload, JSON_THROW_ON_ERROR), now()->timestamp))
        ->postJson('/commands/work.item.update', $payload);

    $response->assertUnauthorized();

    expect($item->refresh()->name)->toBe('Before')
        ->and(CommandWebhookDelivery::query()->count())->toBe(0);
});

it('rejects a capability that is not allowlisted for the authenticated command credential', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = commandWebhookActor($enterprise);

    config()->set('services.command_webhooks.credentials.test-command', [
        'secret' => 'command-secret',
        'actor_id' => $actor->getKey(),
        'capabilities' => ['work.item.update'],
    ]);

    $payload = [
        'enterprise_slug' => $enterprise->slug,
        'idempotency_key' => 'command-capability-denied',
        'correlation_id' => 'command-capability-denied-correlation',
        'input' => ['enterprise_id' => $enterprise->getKey(), 'name' => 'Nope'],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->withHeaders(commandWebhookHeaders('command-secret', $body, now()->timestamp))
        ->postJson('/commands/work.item.create', $payload)
        ->assertForbidden();

    expect(CommandWebhookDelivery::query()->count())->toBe(0);
});

it('prevents duplicate command delivery from producing a second business effect', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = commandWebhookActor($enterprise);
    $item = WorkItem::factory()->create(['enterprise_id' => $enterprise, 'name' => 'Before']);

    config()->set('services.command_webhooks.credentials.test-command', [
        'secret' => 'command-secret',
        'actor_id' => $actor->getKey(),
        'capabilities' => ['work.item.update'],
    ]);

    $payload = [
        'enterprise_slug' => $enterprise->slug,
        'idempotency_key' => 'command-duplicate-1',
        'correlation_id' => 'command-duplicate-correlation',
        'input' => ['work_item_id' => $item->getKey(), 'name' => 'First'],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = commandWebhookHeaders('command-secret', $body, now()->timestamp);

    $first = $this->withHeaders($headers)->postJson('/commands/work.item.update', $payload);
    $secondPayload = $payload;
    $secondPayload['input']['name'] = 'Second';
    $second = $this->withHeaders(commandWebhookHeaders('command-secret', json_encode($secondPayload, JSON_THROW_ON_ERROR), now()->timestamp))
        ->postJson('/commands/work.item.update', $secondPayload);

    $first->assertOk();
    $second->assertOk()->assertJsonPath('result.id', $item->getKey());

    expect($item->refresh()->name)->toBe('First')
        ->and(CommandWebhookDelivery::query()->count())->toBe(1);
});

it('preserves the canonical failure contract for an execution error', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = commandWebhookActor($enterprise);

    config()->set('services.command_webhooks.credentials.test-command', [
        'secret' => 'command-secret',
        'actor_id' => $actor->getKey(),
        'capabilities' => ['work.item.update'],
    ]);

    $payload = [
        'enterprise_slug' => $enterprise->slug,
        'idempotency_key' => 'command-resource-missing',
        'correlation_id' => 'command-resource-missing-correlation',
        'input' => ['work_item_id' => 999999, 'name' => 'Nope'],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $response = $this->withHeaders(commandWebhookHeaders('command-secret', $body, now()->timestamp))
        ->postJson('/commands/work.item.update', $payload);

    $response->assertNotFound()
        ->assertJsonPath('error.code', 'resource.not_found')
        ->assertJsonPath('error.correlation_id', 'command-resource-missing-correlation');

    expect(CommandWebhookDelivery::query()->first()->status)->toBe(CommandWebhookDelivery::STATUS_FAILED);
});

it('does not permit command webhooks to bypass approval-required Capabilities', function (): void {
    $enterprise = Enterprise::factory()->create();
    $actor = commandWebhookActor($enterprise);

    config()->set('services.command_webhooks.credentials.test-command', [
        'secret' => 'command-secret',
        'actor_id' => $actor->getKey(),
        'capabilities' => ['publication.publish'],
    ]);

    $payload = [
        'enterprise_slug' => $enterprise->slug,
        'idempotency_key' => 'command-approval-required',
        'correlation_id' => 'command-approval-required-correlation',
        'input' => [],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->withHeaders(commandWebhookHeaders('command-secret', $body, now()->timestamp))
        ->postJson('/commands/publication.publish', $payload)
        ->assertForbidden();

    expect(CommandWebhookDelivery::query()->count())->toBe(0);
});

it('resolves Enterprise identity through the actor organization boundary', function (): void {
    $enterprise = Enterprise::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create();
    $actor = commandWebhookActor($enterprise);

    config()->set('services.command_webhooks.credentials.test-command', [
        'secret' => 'command-secret',
        'actor_id' => $actor->getKey(),
        'capabilities' => ['work.item.update'],
    ]);

    $item = WorkItem::factory()->create(['enterprise_id' => $foreignEnterprise]);

    $payload = [
        'enterprise_slug' => $foreignEnterprise->slug,
        'idempotency_key' => 'command-cross-enterprise',
        'correlation_id' => 'command-cross-enterprise-correlation',
        'input' => ['work_item_id' => $item->getKey(), 'name' => 'Nope'],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->withHeaders(commandWebhookHeaders('command-secret', $body, now()->timestamp))
        ->postJson('/commands/work.item.update', $payload)
        ->assertNotFound();

    expect($item->refresh()->name)->not->toBe('Nope');
});