<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\Workflow;

final class StrategyCreationWorkflowDefinition
{
    public const CANONICAL_KEY = 'strategy.create';

    /** @return list<array<string, mixed>> */
    public function canonicalStages(): array
    {
        return [
            [
                'key' => 'create_strategy',
                'name' => 'Create Strategy',
                'sequence' => 1,
                'expert_slugs' => ['strategy'],
                'capability_slugs' => [self::CANONICAL_KEY],
                'input_contract' => [
                    'required' => ['objective_id', 'name'],
                ],
                'output_contract' => [
                    'required' => ['id', 'objective_id', 'name'],
                ],
            ],
        ];
    }

    public function createCanonical(Enterprise $enterprise): Workflow
    {
        $stages = $this->canonicalStages();

        $workflow = Workflow::query()->create([
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Create Strategy Workflow',
            'canonical_key' => self::CANONICAL_KEY,
            'purpose' => 'Create a strategy under an authorized enterprise objective.',
            'execution_policy' => [
                'template' => self::CANONICAL_KEY,
                'mode' => 'deterministic',
                'new_only' => true,
                'requires_model_provider' => false,
            ],
            'completion_criteria' => [
                'required_stage_keys' => array_column($stages, 'key'),
            ],
            'status' => Workflow::STATUS_PENDING,
        ]);

        foreach ($stages as $stage) {
            $workflow->stages()->create(array_merge([
                'dependencies' => [],
                'input_contract' => [],
                'output_contract' => [],
                'capability_slugs' => [],
                'expert_slugs' => [],
                'repeatable' => false,
                'completion_criteria' => [
                    'requires_termination_completed' => true,
                ],
            ], $stage));
        }

        return $workflow->load('stages');
    }
}