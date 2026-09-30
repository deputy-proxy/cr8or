<?php

namespace App\Services;

use App\Enums\AgentExecutionMode;
use App\Models\AgentAssignment;
use App\Models\Workflow;

final class WorkflowTemplateResolver
{
    public function __construct(private readonly MarketingStrategyWorkflowDefinition $marketing) {}

    public function resolve(
        AgentAssignment $assignment,
        string $prompt,
        AgentExecutionMode $mode,
        ?string $template = null,
    ): ?Workflow {
        if ($mode !== AgentExecutionMode::AUTONOMOUS || $assignment->agentDescriptor?->slug !== 'marketing') {
            return null;
        }

        if ($template === MarketingStrategyWorkflowDefinition::TEMPLATE
            || ($template === null && preg_match('/\b(full|complete|entire)\b.*\bmarketing\s+strategy\b/i', $prompt) === 1)) {
            if (! $assignment->enterprise) {
                return null;
            }

            return $this->marketing->create($assignment->enterprise, $prompt, MarketingStrategyWorkflowDefinition::TEMPLATE);
        }

        return null;
    }
}
