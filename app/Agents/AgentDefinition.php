<?php

namespace App\Agents;

use App\AI\ReasoningOutputValidator;
use InvalidArgumentException;

final readonly class AgentDefinition
{
    /** @var array<string, mixed> */
    public array $reasoningOutputSchema;

    /**
     * @param  list<string>  $responsibilities
     * @param  list<string>  $experts
     * @param  list<string>  $requiredContext
     * @param  list<string>  $decisionBoundaries
     * @param  list<string>  $expectedOutputs
     * @param  array<string, list<string>>  $expertRouting
     * @param  array<string, mixed>  $reasoningOutputSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $responsibilities,
        public string $instructions,
        public array $experts,
        public array $requiredContext,
        public array $decisionBoundaries = [],
        public array $expectedOutputs = [],
        public array $expertRouting = [],
        array $reasoningOutputSchema = [],
    ) {
        $this->reasoningOutputSchema = $reasoningOutputSchema === []
            ? ReasoningOutputValidator::agentSchema()
            : $reasoningOutputSchema;

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

        foreach ([
            $responsibilities,
            $experts,
            $requiredContext,
            $decisionBoundaries,
            $expectedOutputs,
        ] as $values) {
            foreach ($values as $value) {
                if ($value === '') {
                    throw new InvalidArgumentException("Agent [{$name}] contains an invalid runtime declaration.");
                }
            }
        }

        foreach ($expertRouting as $routingKey => $routedExperts) {
            if ($routingKey === '' || $routedExperts === []) {
                throw new InvalidArgumentException("Agent [{$name}] contains an invalid Expert routing declaration.");
            }

            foreach ($routedExperts as $expert) {
                if ($expert === '' || ! in_array($expert, $experts, true)) {
                    throw new InvalidArgumentException(
                        "Agent [{$name}] routes to an Expert that is not declared by the Agent.",
                    );
                }
            }
        }
    }

    public function version(): string
    {
        return hash('sha256', json_encode([
            'name' => $this->name,
            'description' => $this->description,
            'responsibilities' => $this->responsibilities,
            'instructions' => $this->instructions,
            'experts' => $this->experts,
            'requiredContext' => $this->requiredContext,
            'decisionBoundaries' => $this->decisionBoundaries,
            'expectedOutputs' => $this->expectedOutputs,
            'expertRouting' => $this->expertRouting,
            'reasoningOutputSchema' => $this->reasoningOutputSchema,
        ], JSON_THROW_ON_ERROR));
    }
}
