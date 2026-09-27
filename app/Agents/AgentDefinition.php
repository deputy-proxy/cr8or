<?php

namespace App\Agents;

use InvalidArgumentException;

final readonly class AgentDefinition
{
    /**
     * @param  list<string>  $responsibilities
     * @param  list<string>  $experts
     * @param  list<string>  $requiredContext
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $name, public string $description, public array $responsibilities,
        public string $instructions, public array $experts, public array $requiredContext, public array $capabilities,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Agent identity must define a name.');
        }
        if ($description === '') {
            throw new InvalidArgumentException("Agent [{$name}] must define a description.");
        }
        if ($responsibilities === []) {
            throw new InvalidArgumentException("Agent [{$name}] must define responsibilities.");
        }
        if ($instructions === '') {
            throw new InvalidArgumentException("Agent [{$name}] must define instructions.");
        }
        if ($requiredContext === []) {
            throw new InvalidArgumentException("Agent [{$name}] must define required context.");
        }
        foreach ([$responsibilities, $experts, $requiredContext, $capabilities] as $values) {
            foreach ($values as $value) {
                if ($value === '') {
                    throw new InvalidArgumentException("Agent [{$name}] contains an invalid runtime declaration.");
                }
            }
        }
    }
}
