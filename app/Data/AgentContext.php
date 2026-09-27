<?php

namespace App\Data;

use InvalidArgumentException;

final readonly class AgentContext
{
    /** @var list<string> */
    private const SECTION_ORDER = [
        'enterprise',
        'enterprise_context',
        'strategy',
        'work',
        'knowledge',
        'decisions',
        'execution_history',
        'instructions',
    ];

    /**
     * @param  array<string, AgentContextSection>  $sections
     */
    public function __construct(
        private array $sections = [],
    ) {
        foreach ($this->sections as $name => $section) {
            if ($name !== $section->name) {
                throw new InvalidArgumentException('Agent context section keys must match their canonical names.');
            }
        }
    }

    public function has(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    public function section(string $name): ?AgentContextSection
    {
        return $this->sections[$name] ?? null;
    }

    /** @return list<AgentContextSection> */
    public function sections(): array
    {
        $ordered = [];

        foreach (self::SECTION_ORDER as $name) {
            if (isset($this->sections[$name])) {
                $ordered[] = $this->sections[$name];
            }
        }

        foreach ($this->sections as $name => $section) {
            if (! in_array($name, self::SECTION_ORDER, true)) {
                $ordered[] = $section;
            }
        }

        return $ordered;
    }

    /**
     * Add or replace one current-execution context section.
     *
     * Persistent Agent memory is intentionally not part of this contract.
     */
    public function withSection(AgentContextSection $section): self
    {
        return new self([
            ...$this->sections,
            $section->name => $section,
        ]);
    }

    /**
     * Serialize the context payload consumed by existing Agent/Expert runtimes.
     *
     * Metadata remains available through sections() and is not mixed into model data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $context = [];

        foreach ($this->sections() as $section) {
            $context[$section->name] = $section->data;
        }

        // Preserve the existing Agent/Expert runtime shape while the canonical
        // contract keeps Enterprise identity and Enterprise Context separate.
        if ($this->has('enterprise') && $this->has('enterprise_context')) {
            $context['enterprise'] = [
                'enterprise' => $this->section('enterprise')->data,
                'context' => $this->section('enterprise_context')->data,
            ];
        }

        return $context;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function metadata(): array
    {
        $metadata = [];

        foreach ($this->sections() as $section) {
            $metadata[$section->name] = $section->toArray()['metadata'];
        }

        return $metadata;
    }
}
