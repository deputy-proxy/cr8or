<?php

namespace App\Services;

use App\Capabilities\CapabilityDefinition;
use App\Capabilities\CapabilityRegistry;
use App\Experts\ExpertRegistry;
use Illuminate\Auth\Access\AuthorizationException;

final class ExpertCapabilityResolver
{
    public function __construct(
        private readonly ExpertRegistry $experts,
        private readonly CapabilityRegistry $capabilities,
    ) {}

    /** @return array<string, CapabilityDefinition> */
    public function capabilitiesFor(string $expertSlug): array
    {
        $expert = $this->experts->resolve($expertSlug);
        $definitions = [];

        foreach ($expert->capabilities() as $capability) {
            $definitions[$capability] = $this->capabilities->resolve($capability);
        }

        return $definitions;
    }

    /** @return array<string, string> */
    public function capabilityOptions(string $expertSlug): array
    {
        return collect($this->capabilitiesFor($expertSlug))
            ->mapWithKeys(fn (CapabilityDefinition $definition): array => [$definition->key => $definition->key])
            ->all();
    }

    public function resolve(string $expertSlug, string $capability): CapabilityDefinition
    {
        $definition = $this->capabilitiesFor($expertSlug)[$capability] ?? null;

        if (! $definition instanceof CapabilityDefinition) {
            throw new AuthorizationException(
                "Expert [{$expertSlug}] does not expose Capability [{$capability}].",
            );
        }

        return $definition;
    }
}