<?php

namespace App\Capabilities;

use App\Contracts\Operation;
use InvalidArgumentException;

final readonly class CapabilityDefinition
{
    public function __construct(
        public string $key,
        public string $operation,
    ) {
        if ($key === '' || ! preg_match('/^[a-z0-9]+(?:\.[a-z0-9_]+)+$/', $key)) {
            throw new InvalidArgumentException("Invalid capability identifier [{$key}].");
        }

        if (! is_a($operation, Operation::class, true)) {
            throw new InvalidArgumentException("Capability [{$key}] must resolve to an Operation.");
        }
    }
}
