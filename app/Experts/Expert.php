<?php

namespace App\Experts;

abstract class Expert
{
    abstract public function name(): string;

    abstract public function description(): string;

    /** @return list<string> */
    abstract public function responsibilities(): array;

    /** @return list<string> */
    abstract public function capabilities(): array;

    /** @return list<string> */
    abstract public function requiredContext(): array;

    abstract public function methodology(): string;

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
