<?php

namespace App\Agents;

use App\Experts\Expert;

abstract class Agent
{
    abstract public function name(): string;

    abstract public function description(): string;

    /** @return list<string> */
    abstract public function responsibilities(): array;

    /** @return list<string> */
    abstract public function capabilities(): array;

    /** @return list<string> */
    abstract public function requiredContext(): array;

    /**
     * Coordinate the supplied experts over the same authorized context.
     *
     * The Agent only orchestrates runtime components. It does not persist state,
     * access Eloquent models, or grant authority to Experts.
     *
     * @param  array<string, mixed>  $context
     * @param  iterable<Expert>      $experts
     * @return array{agent: string, results: list<array{expert: string, result: array<string, mixed>}>}
     */
    public function coordinate(array $context, iterable $experts): array
    {
        $results = [];

        foreach ($experts as $expert) {
            $results[] = [
                'expert' => $expert->name(),
                'result' => $expert->analyze($context),
            ];
        }

        return [
            'agent' => $this->name(),
            'results' => $results,
        ];
    }
}
