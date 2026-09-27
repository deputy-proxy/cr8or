<?php

namespace App\Experts;

abstract class Expert
{
    abstract public function definition(): ExpertDefinition;

    final public function name(): string
    {
        return $this->definition()->name;
    }

    final public function description(): string
    {
        return $this->definition()->description;
    }

    /** @return list<string> */
    final public function responsibilities(): array
    {
        return $this->definition()->responsibilities;
    }

    /** @return list<string> */
    final public function capabilities(): array
    {
        return $this->definition()->capabilities;
    }

    /** @return list<string> */
    final public function requiredContext(): array
    {
        return $this->definition()->requiredContext;
    }

    final public function methodology(): string
    {
        return $this->definition()->methodology;
    }

    /**
     * Apply the expert's methodology to authorized context.
     *
     * Provider integration and concrete application capabilities are intentionally
     * outside this runtime contract.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    abstract public function analyze(array $context): array;
}