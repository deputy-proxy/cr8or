<?php

namespace App\Providers;

use App\Contracts\PublishingAdapter;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingAdapterResult;
use App\Data\PublishingCapabilities;
use App\Exceptions\PublishingAdapterException;
use Illuminate\Support\Str;

final class FakePublishingAdapter implements PublishingAdapter
{
    public bool $shouldTimeout = false;

    public bool $shouldFail = false;

    public bool $shouldRateLimit = false;

    public bool $shouldRequireAuthentication = false;

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
            ['linkedin'],
            ['image/jpeg', 'image/png', 'image/webp', 'video/mp4'],
            maxTextLength: 5000,
            supportsScheduling: true,
            supportsIdempotency: true,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        if ($this->shouldTimeout) {
            throw new PublishingAdapterException('Fake network timed out.', 'timeout', true, ambiguous: true);
        }

        if ($this->shouldRateLimit) {
            throw new PublishingAdapterException('Fake network rate limit reached.', 'rate_limited', true);
        }

        if ($this->shouldRequireAuthentication) {
            throw new PublishingAdapterException('Network authorization must be renewed.', 'authentication_required');
        }

        if ($this->shouldFail) {
            throw new PublishingAdapterException('Fake network rejected the publication.', 'provider_rejected');
        }

        $id = 'fake-'.Str::uuid();

        return new PublishingAdapterResult(
            $id,
            'https://fake.publishing.local/posts/'.$id,
            'submitted',
            [
                'network' => $request->network,
                'destination_external_id' => $request->destinationExternalId,
                'credential_reference_present' => $request->credentialReference !== null,
            ],
        );
    }
}