<?php

namespace Tests\Support\Publishing;

use App\Contracts\PublishingAdapter;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingAdapterResult;
use App\Data\PublishingCapabilities;

abstract class FakeNetworkPublishingAdapter implements PublishingAdapter
{
    abstract protected function network(): string;

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
        return $operation === 'publication.publish';
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            [$this->network()],
            ['image/jpeg', 'image/png', 'image/webp', 'video/mp4'],
            maxTextLength: 5000,
            supportsScheduling: true,
            supportsIdempotency: true,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        $id = $this->network().'-'.substr(hash('sha256', $request->idempotencyKey), 0, 20);

        return new PublishingAdapterResult(
            $id,
            'https://'.$this->network().'.example/posts/'.$id,
            'submitted',
            [
                'network' => $request->network,
                'destination_external_id' => $request->destinationExternalId,
                'credential_reference_present' => $request->credentialReference !== null,
            ],
        );
    }
}