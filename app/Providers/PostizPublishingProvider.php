<?php

namespace App\Providers;

use App\Contracts\PublishingProvider;
use App\Data\PublishingProviderResult;
use App\Data\PublishingRequest;
use App\Exceptions\PublishingProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class PostizPublishingProvider implements PublishingProvider
{
    public function publish(PublishingRequest $r): PublishingProviderResult
    {
        $key = (string) config('services.postiz.key', '');

        if ($key === '') {
            throw new PublishingProviderException('Postiz API credentials are not configured.', 'provider_unavailable', true);
        }

        try {
            $x = Http::withToken($key)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.postiz.timeout', 15))
                ->withHeaders(['Idempotency-Key' => $r->idempotencyKey])
                ->post(
                    rtrim((string) config('services.postiz.url'), '/').'/posts',
                    [
                        'type' => 'schedule',
                        'date' => $r->scheduledAt,
                        'posts' => [[
                            'integration' => ['id' => $r->integrationId],
                            'value' => [['content' => $r->content, 'image' => []]],
                            'settings' => $r->settings,
                        ]],
                    ],
                );
        } catch (ConnectionException $e) {
            throw new PublishingProviderException('Postiz connection failed.', 'timeout', true, $e);
        }

        if ($x->successful()) {
            $p = $x->json();
            $id = data_get($p, 'id') ?? data_get($p, 'postId') ?? data_get($p, 'posts.0.id');

            if (! is_string($id) || $id === '') {
                throw new PublishingProviderException('Postiz returned no external publication identifier.', 'invalid_provider_response', true);
            }

            return new PublishingProviderResult(
                $id,
                is_string(data_get($p, 'url')) ? data_get($p, 'url') : null,
                'submitted',
                is_array($p) ? $p : [],
            );
        }

        throw new PublishingProviderException(
            'Postiz rejected the publication request.',
            $x->status() === 429 ? 'rate_limited' : ($x->serverError() ? 'provider_unavailable' : 'provider_rejected'),
            $x->status() === 429 || $x->serverError(),
        );
    }
}
