<?php

use App\Contracts\PublishingAdapter;
use App\Contracts\PublishingStatusLookupAdapter;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingCapabilities;
use App\Data\PublishingRemotePublication;
use App\Enums\PublishingCapabilitySupport;
use App\Exceptions\PublishingAdapterException;
use App\Services\PublishingAdapterRegistry;
use InvalidArgumentException;
use LogicException;
use Tests\Support\Publishing\FakeFacebookPublishingAdapter;
use Tests\Support\Publishing\FakeInstagramPublishingAdapter;
use Tests\Support\Publishing\FakeLinkedInPublishingAdapter;
use Tests\Support\Publishing\FakeNetworkPublishingAdapter;
use Tests\Support\Publishing\FakeThreadsPublishingAdapter;
use Tests\Support\Publishing\FakeTikTokPublishingAdapter;
use Tests\Support\Publishing\FakeYouTubePublishingAdapter;

final class FakeAdapterWithUndeclaredStatusLookup implements PublishingAdapter
{
    public function network(): string
    {
        return 'linkedin';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'linkedin';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'publication.publish';
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'linkedin',
            supportedAccountTypes: ['test_account'],
            supportedDestinationTypes: ['test_destination'],
            statusLookup: PublishingCapabilitySupport::Supported,
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): \App\Data\PublishingAdapterResult
    {
        return new \App\Data\PublishingAdapterResult(
            'test-external-id',
            'https://linkedin.example/posts/test-external-id',
            'submitted',
            $request->correlationId,
            $request->idempotencyKey,
        );
    }
}

final class FakeAdapterWithMismatchedCapabilityNetwork implements PublishingAdapter
{
    public function network(): string
    {
        return 'linkedin';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'linkedin';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'publication.publish';
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'instagram',
            supportedAccountTypes: ['test_account'],
            supportedDestinationTypes: ['test_destination'],
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): \App\Data\PublishingAdapterResult
    {
        return new \App\Data\PublishingAdapterResult(
            'test-external-id',
            'https://linkedin.example/posts/test-external-id',
            'submitted',
            $request->correlationId,
            $request->idempotencyKey,
        );
    }
}

/** @return array<string, class-string<FakeNetworkPublishingAdapter>> */
function publishingAdapterContractCases(): array
{
    return [
        'linkedin' => FakeLinkedInPublishingAdapter::class,
        'facebook' => FakeFacebookPublishingAdapter::class,
        'instagram' => FakeInstagramPublishingAdapter::class,
        'tiktok' => FakeTikTokPublishingAdapter::class,
        'youtube' => FakeYouTubePublishingAdapter::class,
        'threads' => FakeThreadsPublishingAdapter::class,
    ];
}

function publishingAdapterContractRequest(string $network, string $idempotencyKey = 'idempotency-key'): PublishingAdapterRequest
{
    return new PublishingAdapterRequest(
        network: $network,
        destinationExternalId: 'test-destination-'.$network,
        content: 'Conformance publication',
        media: [],
        scheduledAt: null,
        idempotencyKey: $idempotencyKey,
        correlationId: 'correlation-'.$network,
        integrationConnectionId: 41,
        credentialReference: 'vault/reference-only',
    );
}

it('declares a complete capability matrix for every target network adapter', function () {
    foreach (publishingAdapterContractCases() as $network => $adapterClass) {
        $adapter = new $adapterClass;
        $capabilities = $adapter->capabilities();

        expect($adapter)->toBeInstanceOf(PublishingAdapter::class)
            ->and($adapter->network())->toBe($network)
            ->and($adapter->providerKey())->toBe($network)
            ->and($capabilities->network)->toBe($network)
            ->and($capabilities->supportedAccountTypes)->not->toBeEmpty()
            ->and($capabilities->supportedDestinationTypes)->not->toBeEmpty()
            ->and($capabilities->supportedMediaTypes)->toContain('image/jpeg')
            ->and($capabilities->maxTextLength)->toBeGreaterThan(0)
            ->and($capabilities->maxMediaItems)->toBeGreaterThan(0)
            ->and($capabilities->maxMediaSizeBytes)->toBeGreaterThan(0)
            ->and($capabilities->maxVideoDurationSeconds)->toBeGreaterThan(0)
            ->and($capabilities->supportsNetwork($network))->toBeTrue()
            ->and($capabilities->supportsNetwork('not-'.$network))->toBeFalse()
            ->and($capabilities->immediatePublishing)->toBe(PublishingCapabilitySupport::Supported)
            ->and($capabilities->nativeScheduling)->toBe(PublishingCapabilitySupport::Supported)
            ->and($capabilities->statusLookup)->toBe(PublishingCapabilitySupport::Supported)
            ->and($capabilities->editing)->toBe(PublishingCapabilitySupport::Unsupported)
            ->and($capabilities->deletion)->toBe(PublishingCapabilitySupport::Unsupported)
            ->and($capabilities->analytics)->toBe(PublishingCapabilitySupport::Unsupported)
            ->and($capabilities->idempotency)->toBe(PublishingCapabilitySupport::Supported)
            ->and($adapter)->toBeInstanceOf(PublishingStatusLookupAdapter::class);
    }
});

it('preserves normalized result identity and deterministic idempotency across all target networks', function () {
    foreach (publishingAdapterContractCases() as $network => $adapterClass) {
        $adapter = new $adapterClass;
        $request = publishingAdapterContractRequest($network, 'same-key-'.$network);
        $first = $adapter->publish($request);
        $second = $adapter->publish($request);

        $remote = new PublishingRemotePublication(
            network: $network,
            destinationExternalId: $request->destinationExternalId,
            externalPublicationId: $first->externalId,
            idempotencyKey: $request->idempotencyKey,
            correlationId: $request->correlationId,
            integrationConnectionId: $request->integrationConnectionId,
            credentialReference: $request->credentialReference,
        );

        expect($first->status)->toBe('submitted')
            ->and($first->externalId)->toBe($network.'-'.substr(hash('sha256', $request->idempotencyKey), 0, 20))
            ->and($first->externalId)->toBe($second->externalId)
            ->and($first->correlationId)->toBe($request->correlationId)
            ->and($first->idempotencyKey)->toBe($request->idempotencyKey)
            ->and($adapter->publishCalls)->toBe(1)
            ->and($adapter->lookupStatus($remote)?->externalId)->toBe($first->externalId)
            ->and(json_encode($first->payload))->not->toContain('vault/reference-only');
    }
});

it('normalizes rejection, authentication, rate-limit and ambiguous-timeout failures across all target networks', function () {
    $scenarios = [
        'rejection' => ['provider_rejected', false, false],
        'authentication' => ['authentication_required', false, false],
        'rate_limit' => ['rate_limited', true, false],
        'timeout' => ['timeout', true, true],
    ];

    foreach (publishingAdapterContractCases() as $network => $adapterClass) {
        foreach ($scenarios as $scenario => [$code, $retryable, $ambiguous]) {
            $adapter = new $adapterClass;
            match ($scenario) {
                'rejection' => $adapter->shouldFail = true,
                'authentication' => $adapter->shouldRequireAuthentication = true,
                'rate_limit' => $adapter->shouldRateLimit = true,
                'timeout' => $adapter->shouldTimeout = true,
            };

            $exception = null;
            try {
                $adapter->publish(publishingAdapterContractRequest($network, $scenario.'-'.$network));
            } catch (PublishingAdapterException $caught) {
                $exception = $caught;
            }

            if (! $exception instanceof PublishingAdapterException) {
                throw new LogicException("Fake adapter [{$network}] did not produce the expected [{$scenario}] failure.");
            }

            expect($exception->failureCode)->toBe($code)
                ->and($exception->retryable)->toBe($retryable)
                ->and($exception->ambiguous)->toBe($ambiguous);
        }
    }
});

it('can discover a remote acceptance after an ambiguous timeout without resubmitting', function () {
    foreach (publishingAdapterContractCases() as $network => $adapterClass) {
        $adapter = new $adapterClass;
        $adapter->shouldTimeout = true;
        $adapter->acceptBeforeTimeout = true;
        $request = publishingAdapterContractRequest($network, 'timeout-accepted-'.$network);

        $exception = null;
        try {
            $adapter->publish($request);
        } catch (PublishingAdapterException $caught) {
            $exception = $caught;
        }

        if (! $exception instanceof PublishingAdapterException || ! $exception->ambiguous) {
            throw new LogicException("Fake adapter [{$network}] did not mark the lost response as ambiguous.");
        }

        $externalId = $network.'-'.substr(hash('sha256', $request->idempotencyKey), 0, 20);
        $remote = new PublishingRemotePublication(
            network: $network,
            destinationExternalId: $request->destinationExternalId,
            externalPublicationId: $externalId,
            idempotencyKey: $request->idempotencyKey,
            correlationId: $request->correlationId,
        );

        expect($adapter->lookupStatus($remote)?->externalId)->toBe($externalId)
            ->and($adapter->publish($request)->externalId)->toBe($externalId)
            ->and($adapter->publishCalls)->toBe(1);
    }
});

it('reports unknown remote status without fabricating a publication result', function () {
    foreach (publishingAdapterContractCases() as $network => $adapterClass) {
        $adapter = new $adapterClass;
        $request = publishingAdapterContractRequest($network, 'unknown-status-'.$network);
        $remote = new PublishingRemotePublication(
            network: $network,
            destinationExternalId: $request->destinationExternalId,
            externalPublicationId: 'not-known',
            idempotencyKey: $request->idempotencyKey,
            correlationId: $request->correlationId,
        );

        expect($adapter->lookupStatus($remote))->toBeNull();
    }
});

it('validates capability declarations and requires explicit conditions for conditional support', function () {
    expect(fn () => new PublishingCapabilities(
        network: 'LinkedIn',
        supportedAccountTypes: ['page'],
        supportedDestinationTypes: ['organization'],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new PublishingCapabilities(
        network: 'linkedin',
        supportedAccountTypes: [],
        supportedDestinationTypes: ['organization'],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new PublishingCapabilities(
        network: 'linkedin',
        supportedAccountTypes: ['page'],
        supportedDestinationTypes: ['organization'],
        nativeScheduling: PublishingCapabilitySupport::Conditional,
    ))->toThrow(InvalidArgumentException::class);

    $conditional = new PublishingCapabilities(
        network: 'linkedin',
        supportedAccountTypes: ['page'],
        supportedDestinationTypes: ['organization'],
        nativeScheduling: PublishingCapabilitySupport::Conditional,
        conditionalRequirements: ['native_scheduling' => ['only for eligible organization destinations']],
    );

    expect($conditional->nativeScheduling)->toBe(PublishingCapabilitySupport::Conditional)
        ->and($conditional->supportsScheduling)->toBeFalse();
});

it('resolves each target network through its unique configured adapter key', function () {
    $definitions = [];
    foreach (publishingAdapterContractCases() as $network => $adapterClass) {
        $definitions[$network] = [
            'key' => $network,
            'class' => $adapterClass,
            'enabled' => true,
            'native_scheduling' => true,
        ];
    }
    config(['publishing.adapters' => $definitions]);

    foreach (publishingAdapterContractCases() as $network => $adapterClass) {
        $resolution = app(PublishingAdapterRegistry::class)->resolve($network);

        expect($resolution->network)->toBe($network)
            ->and($resolution->adapterKey)->toBe($network)
            ->and($resolution->adapter)->toBeInstanceOf($adapterClass)
            ->and($resolution->useNativeScheduling)->toBeTrue();
    }
});

it('fails closed for unknown networks and duplicate adapter keys', function () {
    expect(fn () => app(PublishingAdapterRegistry::class)->resolve('unknown-network'))
        ->toThrow(PublishingAdapterException::class);

    config(['publishing.adapters' => [
        'linkedin' => ['key' => 'shared-adapter', 'class' => FakeLinkedInPublishingAdapter::class, 'enabled' => true],
        'instagram' => ['key' => 'shared-adapter', 'class' => FakeInstagramPublishingAdapter::class, 'enabled' => true],
    ]]);

    expect(fn () => app(PublishingAdapterRegistry::class)->resolve('linkedin'))
        ->toThrow(LogicException::class, 'registered more than once');
});

it('rejects adapters that claim a different network than the configured destination', function () {
    config(['publishing.adapters' => [
        'linkedin' => [
            'key' => 'linkedin',
            'class' => FakeInstagramPublishingAdapter::class,
            'enabled' => true,
        ],
    ]]);

    expect(fn () => app(PublishingAdapterRegistry::class)->resolve('linkedin'))
        ->toThrow(LogicException::class, 'declares network');
});

it('requires optional contracts to match declared capability support', function () {
    config(['publishing.adapters' => [
        'linkedin' => [
            'key' => 'linkedin',
            'class' => FakeAdapterWithUndeclaredStatusLookup::class,
            'enabled' => true,
        ],
    ]]);

    expect(fn () => app(PublishingAdapterRegistry::class)->resolve('linkedin'))
        ->toThrow(LogicException::class, 'declares [status_lookup]');
});

it('rejects a capability declaration that names a different network', function () {
    config(['publishing.adapters' => [
        'linkedin' => [
            'key' => 'linkedin',
            'class' => FakeAdapterWithMismatchedCapabilityNetwork::class,
            'enabled' => true,
        ],
    ]]);

    expect(fn () => app(PublishingAdapterRegistry::class)->resolve('linkedin'))
        ->toThrow(LogicException::class, 'does not declare support for network');
});