<?php

namespace App\Agents;

use App\Experts\Expert;

abstract class Agent
{
    abstract public function definition(): AgentDefinition;

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

    final public function instructions(): string
    {
        return $this->definition()->instructions;
    }

    /** @return list<string> */
    final public function experts(): array
    {
        return $this->definition()->experts;
    }

    /** @return list<string> */
    final public function requiredContext(): array
    {
        return $this->definition()->requiredContext;
    }

    /** @return list<string> */
    final public function capabilities(): array
    {
        return $this->definition()->capabilities;
    }

    /** @return list<string> */
    final public function decisionBoundaries(): array
    {
        return $this->definition()->decisionBoundaries;
    }

    /** @return list<string> */
    final public function expectedOutputs(): array
    {
        return $this->definition()->expectedOutputs;
    }

    /** @return array<string, list<string>> */
    final public function capabilityMap(): array
    {
        return $this->definition()->capabilityMap;
    }

    /** @return list<string> */
    final public function capabilityGaps(): array
    {
        return $this->definition()->capabilityGaps;
    }

    /** @return list<string> */
    final public function approvalSensitiveCapabilities(): array
    {
        return $this->definition()->approvalSensitiveCapabilities;
    }

    final public function definitionVersion(): string
    {
        return $this->definition()->version();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  iterable<Expert>  $experts
     * @return array<string, mixed>
     */
    public function execute(array $context, iterable $experts): array
    {
        $results = [];
        foreach ($experts as $expert) {
            $results[] = ['expert' => $expert->name(), 'result' => $expert->analyze($context)];
        }

        return ['agent' => $this->name(), 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  iterable<Expert>  $experts
     * @return array<string, mixed>
     */
    public function coordinate(array $context, iterable $experts): array
    {
        return $this->execute($context, $experts);
    }
}

