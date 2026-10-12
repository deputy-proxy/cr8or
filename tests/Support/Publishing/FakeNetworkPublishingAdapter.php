<?php

namespace Tests\Support\Publishing;

use App\Contracts\PublishingStatusLookupAdapter;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingAdapterResult;
use App\Data\PublishingCapabilities;
use App\Data\PublishingRemotePublication;
use App\Enums\PublishingCapabilitySupport;
use App\Exceptions\PublishingAdapterException;

abstract class FakeNetworkPublishingAdapter implements PublishingStatusLookupAdapter
{
    public bool $shouldTimeout = false;

    public bool $acceptBeforeTimeout = false;

    public bool $shouldFail = false;

    public bool $shouldRateLimit = false;

    public bool $shouldRequireAuthentication = false;

    public int $publishCalls = 0;

    /** @var array<string, PublishingAdapterResult> */
    private array $publishedByIdempotencyKey = [];

    abstract protected function networkKey(): string;

    public function network(): string
    {
        return $this->networkKey();
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return $this->network();
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['publication.publish', 'publication.status.lookup'], true);
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: $this->network(),
            supportedAccountTypes: ['test_account'],
            supportedDestinationTypes: ['test_destination'],
            supportedMediaTypes: ['image/jpeg', 'image/png', 'image/webp', 'video/mp4'],
            maxTextLength: 5000,
            maxMediaItems: 4,
            maxMediaSizeBytes: 52_428_800,
            maxVideoDurationSeconds: 300,
            supportedAspectRatios: ['1:1', '4:5', '16:9', '9:16'],
            immediatePublishing: PublishingCapabilitySupport::Supported,
            nativeScheduling: PublishingCapabilitySupport::Supported,
            statusLookup: PublishingCapabilitySupport::Supported,
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        if ($request->network !== $this->network()) {
            throw new PublishingAdapterException('The fake adapter received a request for another network.', 'network_mismatch');
        }

        if (isset($this->publishedByIdempotencyKey[$request->idempotencyKey])) {
            return $this->publishedByIdempotencyKey[$request->idempotencyKey];
        }

        $this->publishCalls++;

        if ($this->shouldRequireAuthentication) {
            throw new PublishingAdapterException('Network authorization must be renewed.', 'authentication_required');
        }

        if ($this->shouldRateLimit) {
            throw new PublishingAdapterException('Fake network rate limit reached.', 'rate_limited', true);
        }

        if ($this->shouldFail) {
            throw new PublishingAdapterException('Fake network rejected the publication.', 'provider_rejected');
        }

        $externalId = $this->network().'-'.substr(hash('sha256', $request->idempotencyKey), 0, 20);
        $result = new PublishingAdapterResult(
            $externalId,
            'https://'.$this->network().'.example/posts/'.$externalId,
            'submitted',
            $request->correlationId,
            $request->idempotencyKey,
            [
                'network' => $request->network,
                'destination_external_id' => $request->destinationExternalId,
                'credential_reference_present' => $request->credentialReference !== null,
            ],
        );

        if ($this->shouldTimeout) {
            if ($this->acceptBeforeTimeout) {
                $this->publishedByIdempotencyKey[$request->idempotencyKey] = $result;
            }

            throw new PublishingAdapterException('Fake network timed out.', 'timeout', true, ambiguous: true);
        }

        $this->publishedByIdempotencyKey[$request->idempotencyKey] = $result;

        return $result;
    }

    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult
    {
        if ($publication->network !== $this->network()) {
            throw new PublishingAdapterException('The status lookup targets another network.', 'network_mismatch');
        }

        $result = $this->publishedByIdempotencyKey[$publication->idempotencyKey] ?? null;
        if ($result === null
            || $result->externalId !== $publication->externalPublicationId
            || ($result->payload['destination_external_id'] ?? null) !== $publication->destinationExternalId) {
            return null;
        }

        return $result;
    }
}