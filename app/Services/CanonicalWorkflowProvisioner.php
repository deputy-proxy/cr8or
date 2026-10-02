<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

final class CanonicalWorkflowProvisioner
{
    public function __construct(
        private readonly MarketingStrategyWorkflowDefinition $definition,
        private readonly StrategyCreationWorkflowDefinition $strategyDefinition,
        private readonly WorkflowVersionService $versions,
    ) {}

    public function provisionStrategyCreation(Enterprise $enterprise, User $actor): Workflow
    {
        return DB::transaction(function () use ($enterprise, $actor): Workflow {
            $workflow = Workflow::query()
                ->forCanonicalKey($enterprise, StrategyCreationWorkflowDefinition::CANONICAL_KEY)
                ->with('publishedVersion')
                ->lockForUpdate()
                ->first();

            if ($workflow === null) {
                $workflow = $this->strategyDefinition->createCanonical($enterprise);
            }

            if ($workflow->publishedVersion?->status !== WorkflowVersion::STATUS_PUBLISHED) {
                $this->versions->publish(
                    $workflow,
                    $actor,
                    'canonical:'.$enterprise->getKey().':'.StrategyCreationWorkflowDefinition::CANONICAL_KEY
                );
            }

            return $workflow->refresh()->load('publishedVersion', 'stages');
        });
    }

    public function provisionMarketingStrategy(Enterprise $enterprise, User $actor): Workflow
    {
        return DB::transaction(function () use ($enterprise, $actor): Workflow {
            $workflow = Workflow::query()
                ->forCanonicalKey($enterprise, MarketingStrategyWorkflowDefinition::CANONICAL_TEMPLATE)
                ->with('publishedVersion')
                ->lockForUpdate()
                ->first();

            if ($workflow === null) {
                $workflow = $this->definition->createCanonical($enterprise);
            }

            if ($workflow->publishedVersion?->status !== WorkflowVersion::STATUS_PUBLISHED) {
                $this->versions->publish(
                    $workflow,
                    $actor,
                    'canonical:'.$enterprise->getKey().':'.MarketingStrategyWorkflowDefinition::CANONICAL_TEMPLATE
                );
            }

            return $workflow->refresh()->load('publishedVersion', 'stages');
        });
    }
}