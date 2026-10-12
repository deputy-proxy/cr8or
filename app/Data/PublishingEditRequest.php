<?php

namespace App\Data;

use InvalidArgumentException;

/**
 * @param  list<array<string, mixed>>  $media
 * @param  array<string, mixed>  $settings
 */
final readonly class PublishingEditRequest
{
    /**
     * @param  list<array<string, mixed>>  $media
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public PublishingRemotePublication $publication,
        public string $content,
        public array $media = [],
        public array $settings = [],
    ) {
        self::assertMediaList($media);
    }

    /** @param array<array-key, mixed> $media */
    private static function assertMediaList(array $media): void
    {
        if (! array_is_list($media)) {
            throw new InvalidArgumentException('Publishing edit media must be a list.');
        }

        foreach ($media as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Publishing edit media entries must be structured metadata arrays.');
            }
        }
    }
}