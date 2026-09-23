<?php

namespace App\Providers;

use App\Contracts\PublishingProvider;
use App\Data\PublishingProviderResult;
use App\Data\PublishingRequest;
use App\Exceptions\PublishingProviderException;
use Illuminate\Support\Str;

final class FakePublishingProvider implements PublishingProvider
{
    public bool $shouldTimeout = false;

    public bool $shouldFail = false;

    public function publish(PublishingRequest $request): PublishingProviderResult
    {
        if ($this->shouldTimeout) {
            throw new PublishingProviderException('Fake provider timed out.', 'timeout', true);
        }

        if ($this->shouldFail) {
            throw new PublishingProviderException('Fake provider rejected the publication.', 'provider_rejected', false);
        }

        $id = 'fake-'.Str::uuid();

        return new PublishingProviderResult(
            $id,
            'https://fake.postiz.local/posts/'.$id,
            'submitted',
            [
                'provider' => 'fake',
                'integration_id' => $request->integrationId,
            ],
        );
    }
}
