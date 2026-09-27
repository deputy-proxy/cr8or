<?php

namespace App\Experts;

use InvalidArgumentException;

final readonly class ExpertDefinition
{
    /**
     * @param  list<string>  $responsibilities
     * @param  list<string>  $requiredContext
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $responsibilities,
        public string $methodology,
        public array $requiredContext,
        public array $capabilities,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Expert identity must define a name.');
        }

        if ($description === '') {
            throw new InvalidArgumentException("Expert [{$name}] must define a description.");
        }

        if ($responsibilities === []) {
            throw new InvalidArgumentException("Expert [{$name}] must define responsibilities.");
        }

        if ($methodology === '') {
            throw new InvalidArgumentException("Expert [{$name}] must define methodology.");
        }

        if ($requiredContext === []) {
            throw new InvalidArgumentException("Expert [{$name}] must define required context.");
        }

        foreach ([$responsibilities, $requiredContext, $capabilities] as $values) {
            foreach ($values as $value) {
                if ($value === '') {
                    throw new InvalidArgumentException("Expert [{$name}] contains an invalid runtime declaration.");
                }
            }
        }
    }
}