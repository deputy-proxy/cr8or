<?php

namespace App\Providers;

use App\Contracts\CanvaClient;
use App\Data\CanvaDesignRequest;
use App\Data\CanvaDesignResult;
use App\Exceptions\CanvaClientException;
use App\Models\IntegrationConnection;

final class FakeCanvaClient implements CanvaClient
{
    public bool $shouldFail = false;

    public bool $shouldTimeout = false;

    public function integrationKey(): string
    {
        return 'creative';
    }

    public function providerKey(): string
    {
        return 'canva';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'design.create';
    }

    public function createDesign(IntegrationConnection $connection, CanvaDesignRequest $request): CanvaDesignResult
    {
        if ($this->shouldTimeout) {
            throw new CanvaClientException('Fake Canva timeout.', 'timeout', true);
        }

        if ($this->shouldFail) {
            throw new CanvaClientException('Fake Canva rejection.', 'provider_rejected');
        }

        return new CanvaDesignResult(
            'fake-canva-'.bin2hex(random_bytes(6)),
            'https://www.canva.com/design/fake',
            ['title' => $request->title],
        );
    }
}