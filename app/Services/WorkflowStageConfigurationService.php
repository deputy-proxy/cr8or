<?php

namespace App\Services;

use App\Models\Workflow;

final class WorkflowStageConfigurationService
{
    public function __construct(
        private readonly ExpertCapabilityResolver $expertCapabilities,
    ) {}

    public function synchronizeCapabilityContracts(Workflow $workflow): void
    {
        foreach ($workflow->stages()->get() as $stage) {
            $experts = $this->list($stage->expert_slugs);
            $capabilities = $this->list($stage->capability_slugs);

            if (count($experts) !== 1 || count($capabilities) !== 1) {
                continue;
            }

            $definition = $this->expertCapabilities->resolve($experts[0], $capabilities[0]);

            $stage->forceFill([
                'capability_input_contract' => $definition->inputContract,
                'capability_output_contract' => $definition->outputContract,
            ])->saveQuietly();
        }
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
        ));
    }
}