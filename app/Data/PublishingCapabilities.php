<?php

namespace App\Data;

use App\Enums\PublishingCapabilitySupport;
use InvalidArgumentException;

/**
 * The verified capability declaration for one publishing network adapter.
 *
 * Null limits mean that no finite limit is declared, not that a network has no limit.
 * Production adapters must verify limits against the applicable official API contract.
 */
final readonly class PublishingCapabilities
{
    public bool $supportsScheduling;

    public bool $supportsIdempotency;

    /**
     * @param  list<string>  $supportedAccountTypes
     * @param  list<string>  $supportedDestinationTypes
     * @param  list<string>  $supportedMediaTypes
     * @param  list<string>  $supportedAspectRatios
     * @param  array<string, list<string>>  $conditionalRequirements
     */
    public function __construct(
        public string $network,
        public array $supportedAccountTypes,
        public array $supportedDestinationTypes,
        public array $supportedMediaTypes = [],
        public ?int $maxTextLength = null,
        public ?int $maxMediaItems = null,
        public ?int $maxMediaSizeBytes = null,
        public ?int $maxVideoDurationSeconds = null,
        public array $supportedAspectRatios = [],
        public PublishingCapabilitySupport $immediatePublishing = PublishingCapabilitySupport::Supported,
        public PublishingCapabilitySupport $nativeScheduling = PublishingCapabilitySupport::Unsupported,
        public PublishingCapabilitySupport $statusLookup = PublishingCapabilitySupport::Unsupported,
        public PublishingCapabilitySupport $editing = PublishingCapabilitySupport::Unsupported,
        public PublishingCapabilitySupport $deletion = PublishingCapabilitySupport::Unsupported,
        public PublishingCapabilitySupport $analytics = PublishingCapabilitySupport::Unsupported,
        public PublishingCapabilitySupport $idempotency = PublishingCapabilitySupport::Unsupported,
        public array $conditionalRequirements = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $network) !== 1) {
            throw new InvalidArgumentException('Publishing capability network keys must be canonical lowercase identifiers.');
        }

        self::assertStringList($supportedAccountTypes, 'supported account types', required: true);
        self::assertStringList($supportedDestinationTypes, 'supported destination types', required: true);
        self::assertStringList($supportedMediaTypes, 'supported media types');
        self::assertStringList($supportedAspectRatios, 'supported aspect ratios');

        foreach ([
            'supported account types' => $supportedAccountTypes,
            'supported destination types' => $supportedDestinationTypes,
        ] as $name => $types) {
            foreach ($types as $type) {
                if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $type) !== 1) {
                    throw new InvalidArgumentException("Publishing {$name} must use canonical lowercase identifiers.");
                }
            }
        }

        foreach ($supportedMediaTypes as $mediaType) {
            if (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#iD', $mediaType) !== 1) {
                throw new InvalidArgumentException('Supported media types must be valid MIME type identifiers.');
            }
        }

        foreach ($supportedAspectRatios as $aspectRatio) {
            if (preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/D', $aspectRatio) !== 1) {
                throw new InvalidArgumentException('Supported aspect ratios must use positive width:height values.');
            }
        }

        foreach ([
            'maxTextLength' => $maxTextLength,
            'maxMediaItems' => $maxMediaItems,
            'maxMediaSizeBytes' => $maxMediaSizeBytes,
            'maxVideoDurationSeconds' => $maxVideoDurationSeconds,
        ] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException("Publishing capability limit [{$name}] must be positive when declared.");
            }
        }

        $statuses = [
            'immediate_publishing' => $immediatePublishing,
            'native_scheduling' => $nativeScheduling,
            'status_lookup' => $statusLookup,
            'editing' => $editing,
            'deletion' => $deletion,
            'analytics' => $analytics,
            'idempotency' => $idempotency,
        ];

        foreach ($conditionalRequirements as $capability => $requirements) {
            if (! array_key_exists($capability, $statuses)) {
                throw new InvalidArgumentException("Unknown conditional publishing capability [{$capability}].");
            }
            self::assertStringList($requirements, "conditional requirements for {$capability}", required: true);
            if ($statuses[$capability] !== PublishingCapabilitySupport::Conditional) {
                throw new InvalidArgumentException("Capability [{$capability}] can declare conditions only when its support is conditional.");
            }
        }

        foreach ($statuses as $capability => $status) {
            if ($status === PublishingCapabilitySupport::Conditional && ! isset($conditionalRequirements[$capability])) {
                throw new InvalidArgumentException("Conditional publishing capability [{$capability}] must declare its requirements.");
            }
        }

        $this->supportsScheduling = $nativeScheduling === PublishingCapabilitySupport::Supported;
        $this->supportsIdempotency = $idempotency === PublishingCapabilitySupport::Supported;
    }

    public function supportsNetwork(string $network): bool
    {
        return $this->network === $network;
    }

    public function supportsMediaType(string $mimeType): bool
    {
        return in_array($mimeType, $this->supportedMediaTypes, true);
    }

    public function supportsAccountType(string $accountType): bool
    {
        return in_array($accountType, $this->supportedAccountTypes, true);
    }

    public function supportsDestinationType(string $destinationType): bool
    {
        return in_array($destinationType, $this->supportedDestinationTypes, true);
    }

    public function supportFor(string $capability): PublishingCapabilitySupport
    {
        return match ($capability) {
            'immediate_publishing' => $this->immediatePublishing,
            'native_scheduling' => $this->nativeScheduling,
            'status_lookup' => $this->statusLookup,
            'editing' => $this->editing,
            'deletion' => $this->deletion,
            'analytics' => $this->analytics,
            'idempotency' => $this->idempotency,
            default => throw new InvalidArgumentException("Unknown publishing capability [{$capability}]."),
        };
    }

    /** @param array<array-key, mixed> $values */
    private static function assertStringList(array $values, string $name, bool $required = false): void
    {
        if (! array_is_list($values) || ($required && $values === [])) {
            throw new InvalidArgumentException("Publishing {$name} must be a non-empty list when required.");
        }

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("Publishing {$name} cannot contain empty values.");
            }
        }

        if (count($values) !== count(array_unique($values))) {
            throw new InvalidArgumentException("Publishing {$name} cannot contain duplicate values.");
        }
    }
}