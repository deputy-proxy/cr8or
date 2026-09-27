<?php

namespace App\Experts;

use App\AI\ReasoningOutputValidator;
use InvalidArgumentException;

final readonly class ExpertDefinition
{
    /** @var array<string, mixed> */
    public array $reasoningOutputSchema;

    /**
     * @param  list<string>  $responsibilities
     * @param  list<string>  $requiredContext
     * @param  list<string>  $capabilities
     * @param  array<string, mixed>  $reasoningOutputSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $responsibilities,
        public string $methodology,
        public array $requiredContext,
        public array $capabilities,
        array $reasoningOutputSchema = [],
    ) {
        $this->reasoningOutputSchema = $reasoningOutputSchema === []
            ? ReasoningOutputValidator::expertSchema()
            : $reasoningOutputSchema;

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