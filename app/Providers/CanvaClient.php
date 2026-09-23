<?php

namespace App\Providers;

use App\Contracts\CanvaClient as CanvaClientContract;
use App\Contracts\CredentialResolver;
use App\Data\CanvaDesignRequest;
use App\Data\CanvaDesignResult;
use App\Exceptions\CanvaClientException;
use App\Models\IntegrationConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class CanvaClient implements CanvaClientContract
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function createDesign(IntegrationConnection $connection, CanvaDesignRequest $request): CanvaDesignResult
    {
        $token = $this->credentials->resolveAccessToken($connection);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.canva.timeout', 15))
                ->post(rtrim((string) config('services.canva.url', 'https://api.canva.com/rest/v1'), '/').'/designs', [
                    'type' => 'type_and_asset',
                    'design_type' => $request->designType,
                    'title' => $request->title,
                    ...($request->assetId === null ? [] : ['asset_id' => $request->assetId]),
                ]);
        } catch (ConnectionException $e) {
            throw new CanvaClientException('Canva connection failed.', 'timeout', true, $e);
        }

        if ($response->successful()) {
            $design = $response->json('design');

            if (! is_array($design) || ! is_string($design['id'] ?? null)) {
                throw new CanvaClientException('Canva returned an invalid design response.', 'invalid_provider_response', true);
            }

            $url = data_get($design, 'urls.edit_url') ?? data_get($design, 'urls.view_url');

            return new CanvaDesignResult(
                $design['id'],
                is_string($url) ? $url : null,
                $design,
            );
        }

        $status = $response->status();
        throw new CanvaClientException(
            'Canva rejected the design request.',
            $status === 429 ? 'rate_limited' : ($status >= 500 ? 'provider_unavailable' : 'provider_rejected'),
            $status === 429 || $status >= 500,
        );
    }
}