<?php

namespace App\Services\Publishing;

use App\Contracts\CredentialResolver;
use App\Contracts\PublishingStatusLookupAdapter;
use App\Data\PublishingAdapterRequest;
use App\Data\PublishingAdapterResult;
use App\Data\PublishingCapabilities;
use App\Data\PublishingRemotePublication;
use App\Data\Social\SocialTokenSet;
use App\Enums\PublishingCapabilitySupport;
use App\Exceptions\PublishingAdapterException;
use App\Exceptions\SocialAuthorizationException;
use App\Models\IntegrationConnection;
use App\Services\Social\AtProtocolOAuthProvider;
use App\Services\Social\SocialAuthorizationProviderRegistry;
use App\Services\Social\SocialCredentialStore;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class BlueskyPublishingAdapter implements PublishingStatusLookupAdapter
{
    private const MAX_IMAGE_BYTES = 2_000_000;

    private const MAX_IMAGE_ALT_TEXT = 1000;

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly SocialCredentialStore $credentialStore,
        private readonly SocialAuthorizationProviderRegistry $providers,
    ) {}

    public function network(): string
    {
        return 'bsky';
    }

    public function integrationKey(): string
    {
        return 'publishing';
    }

    public function providerKey(): string
    {
        return 'bluesky';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'publication.publish';
    }

    public function capabilities(): PublishingCapabilities
    {
        return new PublishingCapabilities(
            network: 'bsky',
            supportedAccountTypes: ['bsky.account'],
            supportedDestinationTypes: ['bsky.account'],
            supportedMediaTypes: ['image/jpeg', 'image/png'],
            maxTextLength: 300,
            maxMediaItems: 4,
            maxMediaSizeBytes: self::MAX_IMAGE_BYTES,
            immediatePublishing: PublishingCapabilitySupport::Supported,
            nativeScheduling: PublishingCapabilitySupport::Unsupported,
            statusLookup: PublishingCapabilitySupport::Supported,
            editing: PublishingCapabilitySupport::Unsupported,
            deletion: PublishingCapabilitySupport::Unsupported,
            analytics: PublishingCapabilitySupport::Unsupported,
            idempotency: PublishingCapabilitySupport::Supported,
        );
    }

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult
    {
        if ($request->network !== $this->network()) {
            throw new PublishingAdapterException('The Bluesky adapter received a request for another network.', 'network_mismatch');
        }
        if ($request->scheduledAt !== null) {
            throw new PublishingAdapterException('Bluesky native scheduling is unsupported; CR8OR must submit at the scheduled time.', 'scheduling_unsupported');
        }
        $media = $this->validatedMedia($request->media);
        $text = trim($request->content);
        if ($text === '' && $media === []) {
            throw new PublishingAdapterException('Bluesky posts require text or at least one supported image.', 'empty_content');
        }
        $this->assertTextLimits($text);
        $connection = $this->resolveConnection($request->integrationConnectionId, $request->credentialReference);
        $this->credentials->resolveAccessToken($connection, 'bsky');
        $tokens = $this->credentialStore->forConnection($connection)->tokenSet();
        $did = $tokens->providerContext['expected_did'] ?? null;
        if (! is_string($did) || ! hash_equals($did, $request->destinationExternalId)) {
            throw new PublishingAdapterException('The selected Bluesky destination does not match the authenticated DID.', 'invalid_destination');
        }
        $provider = $this->provider();
        $rkey = 'cr8or'.substr(hash('sha256', $request->idempotencyKey), 0, 24);
        $uri = 'at://'.$did.'/app.bsky.feed.post/'.$rkey;
        $record = ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => now()->utc()->format('Y-m-d\\TH:i:s.v\\Z')];
        if ($text !== '') {
            $facets = $this->facets($provider, $tokens, $text);
            if ($facets !== []) {
                $record['facets'] = $facets;
            }
        }
        if ($media !== []) {
            $record['embed'] = $this->imageEmbed($provider, $tokens, $media);
        }
        $existing = $this->getRecord($provider, $tokens, $did, $rkey);
        if ($existing !== null) {
            $existingValue = $existing['value'];
            if (($existingValue['text'] ?? null) !== $text
                || ($existingValue['facets'] ?? []) != ($record['facets'] ?? [])
                || ($existingValue['embed'] ?? null) != ($record['embed'] ?? null)) {
                throw new PublishingAdapterException('The Bluesky idempotency key was already used for different content.', 'idempotency_conflict');
            }

            return $this->result($request, $existing['uri'], $existing['cid'], $did, $rkey);
        }
        try {
            $response = $provider->xrpc($tokens, 'POST', 'com.atproto.repo.createRecord', body: ['repo' => $did, 'collection' => 'app.bsky.feed.post', 'rkey' => $rkey, 'record' => $record]);
        } catch (SocialAuthorizationException $exception) {
            $failure = match ($exception->errorCode) {
                'rate_limited' => 'rate_limited', 'invalid_token' => 'authentication_required', 'provider_timeout' => 'timeout', default => 'provider_error'
            };
            throw new PublishingAdapterException('The Bluesky post request did not return a definitive result; reconcile its deterministic record URI before retrying.', $failure, retryable: $exception->retryable, previous: $exception, ambiguous: in_array($exception->errorCode, ['provider_timeout', 'dpop_nonce_missing'], true));
        }
        if (! $response->successful()) {
            throw $this->apiFailure($response, true);
        }
        $remoteUri = $response->json('uri');
        $cid = $response->json('cid');
        if (! is_string($remoteUri) || ! hash_equals($uri, $remoteUri) || ! is_string($cid) || trim($cid) === '') {
            throw new PublishingAdapterException('Bluesky accepted the request without returning the expected record URI and CID; the outcome is ambiguous.', 'invalid_adapter_response', retryable: true, ambiguous: true);
        }

        return $this->result($request, $remoteUri, $cid, $did, $rkey);
    }

    public function lookupStatus(PublishingRemotePublication $publication): ?PublishingAdapterResult
    {
        if ($publication->network !== $this->network()) {
            throw new PublishingAdapterException('The Bluesky adapter received a status lookup for another network.', 'network_mismatch');
        }
        if (preg_match('~^at://(did:(?:plc:[a-z0-9]+|web:.+))/app\.bsky\.feed\.post/([A-Za-z0-9._\\~:-]+)$~D', $publication->externalPublicationId, $matches) !== 1 || ! hash_equals($matches[1], $publication->destinationExternalId)) {
            throw new PublishingAdapterException('Bluesky status lookup requires a post URI belonging to the selected DID.', 'invalid_destination');
        }
        $connection = $this->resolveConnection($publication->integrationConnectionId, $publication->credentialReference);
        $this->credentials->resolveAccessToken($connection, 'bsky');
        $tokens = $this->credentialStore->forConnection($connection)->tokenSet();
        $record = $this->getRecord($this->provider(), $tokens, $matches[1], $matches[2]);
        if ($record === null) {
            return null;
        }
        if (! hash_equals($publication->externalPublicationId, $record['uri'])) {
            throw new PublishingAdapterException('Bluesky returned a record that does not match the requested publication.', 'invalid_adapter_response');
        }

        return new PublishingAdapterResult($record['uri'], $this->postUrl($matches[1], $matches[2]), 'published', $publication->correlationId, $publication->idempotencyKey, ['network' => 'bsky', 'did' => $matches[1], 'rkey' => $matches[2], 'cid' => $record['cid']]);
    }

    private function provider(): AtProtocolOAuthProvider
    {
        $provider = $this->providers->forProvider('bluesky');
        if (! $provider instanceof AtProtocolOAuthProvider) {
            throw new PublishingAdapterException('The configured Bluesky authorization driver is unavailable.', 'provider_configuration_invalid');
        }

        return $provider;
    }

    private function resolveConnection(?int $connectionId, ?string $credentialReference): IntegrationConnection
    {
        if ($connectionId === null || $credentialReference === null) {
            throw new PublishingAdapterException('Bluesky publishing requires an authorized social connection.', 'authentication_required');
        }
        $connection = IntegrationConnection::query()->find($connectionId);
        if ($connection === null || $connection->provider !== 'bluesky' || ! $connection->isOperational() || ! hash_equals((string) $connection->credential_reference, $credentialReference)) {
            throw new PublishingAdapterException('The Bluesky connection is unavailable or does not match the publication credential reference.', 'authentication_required');
        }

        return $connection;
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return list<array<string, mixed>>
     */
    private function validatedMedia(array $media): array
    {
        if (count($media) > 4) {
            throw new PublishingAdapterException('Bluesky supports at most four images per post.', 'too_many_media_items');
        }

        foreach ($media as $item) {
            $mimeType = $item['mime_type'] ?? null;
            if (! is_string($mimeType) || ! in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
                throw new PublishingAdapterException('Bluesky publishing supports JPEG and PNG images only.', 'unsupported_media');
            }
            $size = $item['size'] ?? null;
            if (! is_numeric($size) || (int) $size < 1 || (int) $size > self::MAX_IMAGE_BYTES) {
                throw new PublishingAdapterException('Each Bluesky image must have a known size no greater than 2,000,000 bytes.', 'media_size_exceeded');
            }
            if (($item['disk'] ?? null) !== 'r2' || ! is_string($item['path'] ?? null) || trim($item['path']) === '') {
                throw new PublishingAdapterException('Bluesky images must be stored in CR8OR R2.', 'media_unavailable');
            }
            $path = trim($item['path']);
            if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
                throw new PublishingAdapterException('The Bluesky image storage path is invalid.', 'media_unavailable');
            }
            $metadata = $item['metadata'] ?? [];
            $altText = is_array($metadata) ? ($metadata['alt_text'] ?? '') : '';
            if (! is_string($altText) || mb_strlen($altText) > self::MAX_IMAGE_ALT_TEXT) {
                throw new PublishingAdapterException('Bluesky image alt text must be a string of at most 1,000 characters.', 'invalid_media_metadata');
            }
            $dimensions = $item['dimensions'] ?? ($metadata['dimensions'] ?? []);
            if ($dimensions !== [] && (! is_array($dimensions) || ! is_numeric($dimensions['width'] ?? null) || ! is_numeric($dimensions['height'] ?? null) || (int) $dimensions['width'] < 1 || (int) $dimensions['height'] < 1)) {
                throw new PublishingAdapterException('Bluesky image dimensions must contain positive width and height values.', 'invalid_media_dimensions');
            }
        }

        return $media;
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return array<string, mixed>
     */
    private function imageEmbed(AtProtocolOAuthProvider $provider, SocialTokenSet $tokens, array $media): array
    {
        $images = [];
        foreach ($media as $item) {
            $bytes = $this->sanitizedImageBytes($item);
            try {
                $blob = $provider->uploadBlob($tokens, $bytes, (string) $item['mime_type']);
            } catch (SocialAuthorizationException $exception) {
                $code = match ($exception->errorCode) {
                    'invalid_token' => 'authentication_required',
                    'rate_limited' => 'rate_limited',
                    'provider_timeout' => 'blob_upload_timeout',
                    default => 'blob_upload_failed',
                };
                throw new PublishingAdapterException('Bluesky could not complete an image blob upload; no post record has been submitted.', $code, retryable: $exception->retryable, previous: $exception);
            }
            $reference = $blob['ref'] ?? null;
            $cid = is_array($reference) ? ($reference['$link'] ?? null) : null;
            if (($blob['$type'] ?? null) !== 'blob' || ! is_string($cid) || trim($cid) === '' || ($blob['mimeType'] ?? null) !== $item['mime_type'] || ($blob['size'] ?? null) !== strlen($bytes)) {
                throw new PublishingAdapterException('Bluesky returned invalid metadata for an uploaded image blob.', 'invalid_adapter_response');
            }
            $metadata = $item['metadata'] ?? [];
            $image = ['alt' => is_array($metadata) ? (string) ($metadata['alt_text'] ?? '') : '', 'image' => $blob];
            $dimensions = $item['dimensions'] ?? ($metadata['dimensions'] ?? []);
            if (is_array($dimensions) && is_numeric($dimensions['width'] ?? null) && is_numeric($dimensions['height'] ?? null)) {
                $image['aspectRatio'] = ['width' => (int) $dimensions['width'], 'height' => (int) $dimensions['height']];
            }
            $images[] = $image;
        }

        return ['$type' => 'app.bsky.embed.images', 'images' => $images];
    }

    /** @param array<string, mixed> $item */
    private function sanitizedImageBytes(array $item): string
    {
        $path = (string) $item['path'];
        try {
            $disk = Storage::disk('r2');
            if (! $disk->exists($path)) {
                throw new PublishingAdapterException('The selected Bluesky image no longer exists in R2.', 'media_unavailable');
            }
            $bytes = $disk->get($path);
        } catch (PublishingAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PublishingAdapterException('CR8OR could not read the selected Bluesky image from R2.', 'media_unavailable', retryable: true, previous: $exception);
        }
        if (! is_string($bytes) || strlen($bytes) !== (int) $item['size'] || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new PublishingAdapterException('The Bluesky image bytes do not match the declared media size.', 'media_unavailable');
        }

        $mimeType = (string) $item['mime_type'];
        $sanitized = match ($mimeType) {
            'image/jpeg' => $this->stripJpegMetadata($bytes),
            'image/png' => $this->stripPngMetadata($bytes),
            default => throw new PublishingAdapterException('Bluesky publishing supports JPEG and PNG images only.', 'unsupported_media'),
        };
        if ($sanitized === '' || strlen($sanitized) > self::MAX_IMAGE_BYTES) {
            throw new PublishingAdapterException('The sanitized Bluesky image is empty or exceeds 2,000,000 bytes.', 'media_size_exceeded');
        }

        return $sanitized;
    }

    private function stripJpegMetadata(string $bytes): string
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            throw new PublishingAdapterException('The Bluesky JPEG bytes are invalid.', 'invalid_media');
        }
        $result = "\xFF\xD8";
        $length = strlen($bytes);
        $offset = 2;
        $hasFrame = false;
        while ($offset < $length) {
            if (ord($bytes[$offset]) !== 0xFF) {
                throw new PublishingAdapterException('The Bluesky JPEG structure is invalid.', 'invalid_media');
            }
            $markerStart = $offset;
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) {
                $offset++;
            }
            if ($offset >= $length) {
                throw new PublishingAdapterException('The Bluesky JPEG structure is truncated.', 'invalid_media');
            }
            $marker = ord($bytes[$offset++]);
            if ($marker === 0xDA) {
                $result .= substr($bytes, $markerStart);

                return $result;
            }
            if (in_array($marker, [0xD8, 0xD9, 0x01, ...range(0xD0, 0xD7)], true)) {
                $result .= substr($bytes, $markerStart, $offset - $markerStart);
                if ($marker === 0xD9) {
                    return $result;
                }

                continue;
            }
            if ($offset + 2 > $length) {
                throw new PublishingAdapterException('The Bluesky JPEG segment is truncated.', 'invalid_media');
            }
            $segmentLengthData = unpack('nlength', substr($bytes, $offset, 2));
            $segmentLength = is_array($segmentLengthData) ? ($segmentLengthData['length'] ?? null) : null;
            if (! is_int($segmentLength)) {
                throw new PublishingAdapterException('The Bluesky JPEG segment length is invalid.', 'invalid_media');
            }
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                throw new PublishingAdapterException('The Bluesky JPEG segment length is invalid.', 'invalid_media');
            }
            $end = $offset + $segmentLength;
            if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
                $hasFrame = true;
            }
            if (! in_array($marker, [0xE1, 0xED, 0xFE], true)) {
                $result .= substr($bytes, $markerStart, $end - $markerStart);
            }
            $offset = $end;
        }

        throw new PublishingAdapterException('The Bluesky JPEG has no image data segment.', 'invalid_media');
    }

    private function stripPngMetadata(string $bytes): string
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (! str_starts_with($bytes, $signature)) {
            throw new PublishingAdapterException('The Bluesky PNG bytes are invalid.', 'invalid_media');
        }
        $result = $signature;
        $length = strlen($bytes);
        $offset = strlen($signature);
        $foundEnd = false;
        $foundHeader = false;
        $foundData = false;
        while ($offset + 12 <= $length) {
            $chunkLengthData = unpack('Nlength', substr($bytes, $offset, 4));
            $chunkLength = is_array($chunkLengthData) ? ($chunkLengthData['length'] ?? null) : null;
            if (! is_int($chunkLength)) {
                throw new PublishingAdapterException('The Bluesky PNG chunk length is invalid.', 'invalid_media');
            }
            $chunkEnd = $offset + 12 + $chunkLength;
            if ($chunkEnd > $length) {
                throw new PublishingAdapterException('The Bluesky PNG chunk length is invalid.', 'invalid_media');
            }
            $type = substr($bytes, $offset + 4, 4);
            $chunkData = substr($bytes, $offset + 8, $chunkLength);
            $storedCrc = substr($bytes, $offset + 8 + $chunkLength, 4);
            if (! hash_equals(hash('crc32b', $type.$chunkData, true), $storedCrc)) {
                throw new PublishingAdapterException('The Bluesky PNG chunk checksum is invalid.', 'invalid_media');
            }
            if ($offset === strlen($signature)) {
                if ($type !== 'IHDR' || $chunkLength !== 13) {
                    throw new PublishingAdapterException('The Bluesky PNG header is invalid.', 'invalid_media');
                }
                $foundHeader = true;
            }
            if ($type === 'IDAT') {
                $foundData = true;
            }
            if ($type === 'IEND' && $chunkLength !== 0) {
                throw new PublishingAdapterException('The Bluesky PNG end chunk is invalid.', 'invalid_media');
            }
            if (! in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
                $result .= substr($bytes, $offset, 12 + $chunkLength);
            }
            $offset = $chunkEnd;
            if ($type === 'IEND') {
                $foundEnd = true;
                break;
            }
        }
        if (! $foundHeader || ! $foundData || ! $foundEnd || $offset !== $length) {
            throw new PublishingAdapterException('The Bluesky PNG is truncated or has trailing data.', 'invalid_media');
        }

        return $result;
    }

    private function assertTextLimits(string $text): void
    {
        preg_match_all('/\X/u', $text, $graphemes);
        if (count($graphemes[0]) > 300 || strlen($text) > 3000) {
            throw new PublishingAdapterException('Bluesky post text must be at most 300 grapheme clusters and 3000 UTF-8 bytes.', 'content_too_long');
        }
    }

    /** @return list<array<string, mixed>> */
    private function facets(AtProtocolOAuthProvider $provider, SocialTokenSet $tokens, string $text): array
    {
        $facets = [];
        preg_match_all('~https?://[^\s<>"\']+~u', $text, $links, PREG_OFFSET_CAPTURE);
        foreach ($links[0] as [$match, $offset]) {
            $url = rtrim($match, '.,;!?)');
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $facets[] = ['index' => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($url)], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]]];
            }
        }
        preg_match_all('/(?<![\pL\pN._-])@([a-z0-9.-]+\.[a-z]{2,63})/iu', $text, $mentions, PREG_OFFSET_CAPTURE);
        foreach ($mentions[1] as [$handle, $offset]) {
            try {
                $did = $provider->resolveMentionDid($tokens, $handle);
            } catch (SocialAuthorizationException $exception) {
                $failureCode = match ($exception->errorCode) {
                    'invalid_token' => 'authentication_required',
                    'rate_limited' => 'rate_limited',
                    'provider_timeout' => 'timeout',
                    default => 'provider_error',
                };
                throw new PublishingAdapterException('Bluesky could not resolve a mention handle.', $failureCode, retryable: $exception->retryable, previous: $exception);
            }
            if ($did !== null) {
                $start = $offset - 1;
                $facets[] = ['index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($handle) + 1], 'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => $did]]];
            }
        }
        preg_match_all('/(?<![\pL\pN_])#([\pL\pN_]{1,64})/u', $text, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[1] as [$tag, $offset]) {
            $start = $offset - 1;
            $facets[] = ['index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($tag) + 1], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag]]];
        }
        usort($facets, fn (array $a, array $b): int => $a['index']['byteStart'] <=> $b['index']['byteStart']);
        $result = [];
        $end = -1;
        foreach ($facets as $facet) {
            if ($facet['index']['byteStart'] < $end) {
                continue;
            } $result[] = $facet;
            $end = $facet['index']['byteEnd'];
        }

        return $result;
    }

    /** @return array{uri: string, cid: string, value: array<string, mixed>}|null */
    private function getRecord(AtProtocolOAuthProvider $provider, SocialTokenSet $tokens, string $did, string $rkey): ?array
    {
        try {
            $response = $provider->xrpc($tokens, 'GET', 'com.atproto.repo.getRecord', ['repo' => $did, 'collection' => 'app.bsky.feed.post', 'rkey' => $rkey]);
        } catch (SocialAuthorizationException $exception) {
            $failureCode = match ($exception->errorCode) {
                'invalid_token' => 'authentication_required',
                'rate_limited' => 'rate_limited',
                'provider_timeout' => 'timeout',
                default => 'provider_error',
            };
            throw new PublishingAdapterException('Bluesky could not reconcile the publication record.', $failureCode, retryable: $exception->retryable, previous: $exception);
        }
        if ($response->successful()) {
            $body = $response->json();
            if (! is_array($body) || ! is_string($body['uri'] ?? null) || ! is_string($body['cid'] ?? null) || ! is_array($body['value'] ?? null)) {
                throw new PublishingAdapterException('Bluesky returned an invalid record lookup response.', 'invalid_adapter_response');
            }

            return ['uri' => $body['uri'], 'cid' => $body['cid'], 'value' => $body['value']];
        }
        if ($response->status() === 404 || ($response->status() === 400 && in_array($response->json('error'), ['RecordNotFound', 'NotFound'], true))) {
            return null;
        }
        throw $this->apiFailure($response, false);
    }

    private function apiFailure(Response $response, bool $submission): PublishingAdapterException
    {
        $status = $response->status();
        if (in_array($status, [401, 403], true)) {
            return new PublishingAdapterException('Bluesky rejected the session or feed-post permission.', 'authentication_required');
        }
        if ($status === 429) {
            return new PublishingAdapterException('Bluesky rate limit reached; retry after the provider backoff window.', 'rate_limited', retryable: true);
        }
        if ($status >= 500) {
            return new PublishingAdapterException($submission ? 'Bluesky returned a server error after submission; reconcile the deterministic record URI before retrying.' : 'Bluesky returned a server error during record lookup.', 'provider_unavailable', retryable: true, ambiguous: $submission);
        }

        return new PublishingAdapterException('Bluesky rejected the request. Check the session, record schema and permissions.', 'provider_rejected');
    }

    private function result(PublishingAdapterRequest $request, string $uri, string $cid, string $did, string $rkey): PublishingAdapterResult
    {
        return new PublishingAdapterResult($uri, $this->postUrl($did, $rkey), 'published', $request->correlationId, $request->idempotencyKey, ['network' => 'bsky', 'did' => $did, 'rkey' => $rkey, 'cid' => $cid]);
    }

    private function postUrl(string $did, string $rkey): string
    {
        return 'https://bsky.app/profile/'.$did.'/post/'.$rkey;
    }
}
