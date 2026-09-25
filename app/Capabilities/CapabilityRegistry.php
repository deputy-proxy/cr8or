<?php

namespace App\Capabilities;

use App\Contracts\Operation;
use App\Mcp\Tools\AnalyzeBusinessContextTool;
use App\Mcp\Tools\CreateContentItemTool;
use App\Mcp\Tools\CreateStrategyTool;
use App\Mcp\Tools\CreateWorkItemTool;
use App\Mcp\Tools\DelegateAgentTool;
use App\Mcp\Tools\GenerateFinancialReportTool;
use App\Mcp\Tools\MarkContentPublicationReadyTool;
use App\Mcp\Tools\PlanMarketingTool;
use App\Mcp\Tools\PublishContentTool;
use App\Mcp\Tools\SubmitContentForReviewTool;
use App\Mcp\Tools\UpdateContentItemTool;
use App\Mcp\Tools\UpdateStrategyTool;
use App\Mcp\Tools\UpdateWorkItemTool;
use App\Operations\AnalyzeBusinessContext;
use App\Operations\CreateContentItem;
use App\Operations\CreateStrategy;
use App\Operations\CreateWorkItem;
use App\Operations\DelegateAgent;
use App\Operations\GenerateFinancialReport;
use App\Operations\MarkContentPublicationReady;
use App\Operations\PlanMarketing;
use App\Operations\PublishContent;
use App\Operations\SubmitContentForReview;
use App\Operations\UpdateContentItem;
use App\Operations\UpdateStrategy;
use App\Operations\UpdateWorkItem;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CapabilityRegistry
{
    /**
     * @param  list<CapabilityDefinition>|null  $definitions
     */
    public function __construct(private readonly ?array $definitions = null) {}

    /**
     * The runtime source of truth for governed Capability → Operation → Tool mappings.
     *
     * @return array<string, CapabilityDefinition>
     */
    public function all(): array
    {
        $definitions = $this->definitions ?? $this->defaultDefinitions();

        $byKey = [];
        $byOperation = [];
        $byTool = [];
        $byToolClass = [];

        foreach ($definitions as $definition) {
            $this->assertUnique($byKey, $definition->key, 'Capability identifier');
            $this->assertUnique($byOperation, $definition->operation, 'Operation');
            $this->assertUnique($byTool, $definition->tool, 'MCP Tool identifier');
            $this->assertUnique($byToolClass, $definition->toolClass, 'MCP Tool class');

            $byKey[$definition->key] = $definition;
            $byOperation[$definition->operation] = $definition;
            $byTool[$definition->tool] = $definition;
            $byToolClass[$definition->toolClass] = $definition;
        }

        return $byKey;
    }

    public function resolve(string $capability): CapabilityDefinition
    {
        return $this->all()[$capability] ?? throw new InvalidArgumentException("Unknown capability [{$capability}].");
    }

    public function forTool(string $tool): CapabilityDefinition
    {
        $definitions = $this->all();

        if (str_contains($tool, '\\')) {
            foreach ($definitions as $definition) {
                if ($definition->toolClass === $tool) {
                    return $definition;
                }
            }

            throw new InvalidArgumentException("Unknown governed MCP Tool [{$tool}].");
        }

        foreach ($definitions as $definition) {
            if ($definition->tool === $tool) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown governed MCP Tool [{$tool}].");
    }

    public function operation(string $capability): Operation
    {
        return app($this->resolve($capability)->operation);
    }

    public function operationForTool(string $tool): Operation
    {
        return $this->operation($this->forTool($tool)->key);
    }

    /**
     * @return list<CapabilityDefinition>
     */
    private function defaultDefinitions(): array
    {
        return [
            $this->definition(
                'agent.delegate',
                DelegateAgent::class,
                DelegateAgentTool::class,
                [
                    'source_agent_assignment_id' => 'integer|required',
                    'target_agent_slug' => 'string|required',
                    'capability' => 'string|required',
                    'prompt' => 'string|required',
                    'target_context' => 'object|nullable',
                    'source_approval_request_id' => 'integer|nullable',
                    'target_approval_request_id' => 'integer|nullable',
                    'parent_agent_execution_id' => 'integer|nullable',
                    'correlation_id' => 'string|nullable',
                    'idempotency_key' => 'string|required',
                ],
                ['delegation_id' => 'integer', 'status' => 'string', 'source_agent' => 'string', 'target_agent' => 'string', 'capability' => 'string', 'correlation_id' => 'string|null', 'execution_id' => 'integer|null'],
                'delegation-service + capability authorization',
                'permission-dependent',
            ),
            $this->definition(
                'business.analysis',
                AnalyzeBusinessContext::class,
                AnalyzeBusinessContextTool::class,
                ['enterprise_id' => 'integer|required', 'target_context' => 'object|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'object'],
                'ExpertCapabilityService + capability authorization',
                'permission-dependent',
            ),
            $this->definition(
                'finance.report.generate',
                GenerateFinancialReport::class,
                GenerateFinancialReportTool::class,
                ['enterprise_id' => 'integer|required', 'financial_period_id' => 'integer|required', 'financial_account_id' => 'integer|nullable', 'transaction_category_id' => 'integer|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'financial-report'],
                'McpCapabilityAuthorizer::authorizeMutation + Finance policy',
                'permission-dependent',
            ),
            $this->definition(
                'marketing.content.create',
                CreateContentItem::class,
                CreateContentItemTool::class,
                ['enterprise_id' => 'integer|required', 'campaign_id' => 'integer|required', 'content_series_id' => 'integer|nullable', 'channel_id' => 'integer|nullable', 'audience_id' => 'integer|nullable', 'title' => 'string|required', 'body' => 'string|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'content-item'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentItem policy',
                'permission-dependent',
            ),
            $this->definition(
                'marketing.content.update',
                UpdateContentItem::class,
                UpdateContentItemTool::class,
                ['content_item_id' => 'integer|required', 'title' => 'string|nullable', 'body' => 'string|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'content-item'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentItem policy',
                'permission-dependent',
            ),
            $this->definition(
                'marketing.content.review',
                SubmitContentForReview::class,
                SubmitContentForReviewTool::class,
                ['content_item_id' => 'integer|required', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'content-item'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentItem policy',
                'permission-dependent',
            ),
            $this->definition(
                'marketing.content.publication-ready',
                MarkContentPublicationReady::class,
                MarkContentPublicationReadyTool::class,
                ['content_item_id' => 'integer|required', 'approval_request_id' => 'integer|required', 'agent_assignment_id' => 'integer|required', 'agent_execution_id' => 'integer|required'],
                ['success' => 'boolean', 'result' => 'content-item'],
                'McpCapabilityAuthorizer::authorizeMutation + explicit approval match',
                'required',
            ),
            $this->definition(
                'marketing.plan',
                PlanMarketing::class,
                PlanMarketingTool::class,
                ['enterprise_id' => 'integer|required', 'target_context' => 'object|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'object'],
                'ExpertCapabilityService + capability authorization',
                'permission-dependent',
            ),
            $this->definition(
                'publication.publish',
                PublishContent::class,
                PublishContentTool::class,
                ['content_item_id' => 'integer|required', 'social_account_id' => 'integer|required', 'scheduled_at' => 'date|required', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'publication'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentItem policy',
                'permission-dependent',
            ),
            $this->definition(
                'strategy.create',
                CreateStrategy::class,
                CreateStrategyTool::class,
                ['objective_id' => 'integer|required', 'name' => 'string|required', 'description' => 'string|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'strategy'],
                'McpCapabilityAuthorizer::authorizeMutation + Strategy policy',
                'permission-dependent',
            ),
            $this->definition(
                'strategy.update',
                UpdateStrategy::class,
                UpdateStrategyTool::class,
                ['strategy_id' => 'integer|required', 'name' => 'string|nullable', 'description' => 'string|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'strategy'],
                'McpCapabilityAuthorizer::authorizeMutation + Strategy policy',
                'permission-dependent',
            ),
            $this->definition(
                'work.item.create',
                CreateWorkItem::class,
                CreateWorkItemTool::class,
                ['enterprise_id' => 'integer|required', 'name' => 'string|required', 'description' => 'string|nullable', 'status' => 'string|nullable', 'project_id' => 'integer|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'work-item'],
                'McpCapabilityAuthorizer::authorizeMutation + WorkItem policy',
                'permission-dependent',
            ),
            $this->definition(
                'work.item.update',
                UpdateWorkItem::class,
                UpdateWorkItemTool::class,
                ['work_item_id' => 'integer|required', 'name' => 'string|nullable', 'description' => 'string|nullable', 'status' => 'string|nullable', 'project_id' => 'integer|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'work-item'],
                'McpCapabilityAuthorizer::authorizeMutation + WorkItem policy',
                'permission-dependent',
            ),
        ];
    }

    /**
     * @param  class-string<Operation>  $operation
     * @param  class-string<\Laravel\Mcp\Server\Tool>  $toolClass
     * @param  array<string, string>  $inputContract
     * @param  array<string, string>  $outputContract
     */
    private function definition(
        string $key,
        string $operation,
        string $toolClass,
        array $inputContract,
        array $outputContract,
        string $authorizationRequirement,
        string $approvalRequirement,
    ): CapabilityDefinition {
        return new CapabilityDefinition(
            key: $key,
            operation: $operation,
            tool: Str::kebab(Str::beforeLast(class_basename($toolClass), 'Tool')),
            toolClass: $toolClass,
            inputContract: $inputContract,
            outputContract: $outputContract,
            authorizationRequirement: $authorizationRequirement,
            approvalRequirement: $approvalRequirement,
        );
    }

    /**
     * @param  array<string, CapabilityDefinition>  $index
     */
    private function assertUnique(array $index, string $value, string $label): void
    {
        if (isset($index[$value])) {
            throw new InvalidArgumentException("Duplicate {$label} [{$value}] in governed Capability registry.");
        }
    }
}