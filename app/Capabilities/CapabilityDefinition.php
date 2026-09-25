<?php

namespace App\Capabilities;

use App\Contracts\Operation;
use InvalidArgumentException;
use Laravel\Mcp\Server\Tool;

final readonly class CapabilityDefinition
{
    /**
     * @param  array<string, string>  $inputContract
     * @param  array<string, string>  $outputContract
     */
    public function __construct(
        public string $key,
        public string $operation,
        public string $tool,
        public string $toolClass,
        public array $inputContract,
        public array $outputContract,
        public string $authorizationRequirement,
        public string $approvalRequirement,
    ) {
        if ($key === '' || ! preg_match('/^[a-z0-9]+(?:\.[a-z0-9_-]+)+$/', $key)) {
            throw new InvalidArgumentException("Invalid capability identifier [{$key}].");
        }

        if (! is_a($operation, Operation::class, true)) {
            throw new InvalidArgumentException("Capability [{$key}] must resolve to an Operation.");
        }

        if ($tool === '' || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $tool)) {
            throw new InvalidArgumentException("Invalid MCP Tool name [{$tool}].");
        }

        if (! is_a($toolClass, Tool::class, true)) {
            throw new InvalidArgumentException("Capability [{$key}] must resolve to an MCP Tool.");
        }

        if ($inputContract === [] || $outputContract === []) {
            throw new InvalidArgumentException("Capability [{$key}] must define input and output contracts.");
        }

        if ($authorizationRequirement === '') {
            throw new InvalidArgumentException("Capability [{$key}] must define authorization requirements.");
        }

        if ($approvalRequirement === '') {
            throw new InvalidArgumentException("Capability [{$key}] must define approval requirements.");
        }
    }
}