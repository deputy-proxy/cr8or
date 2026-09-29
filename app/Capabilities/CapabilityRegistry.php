<?php

namespace App\Capabilities;

use App\Contracts\Operation;
use App\Mcp\Tools\AnalyzeBusinessContextTool;
use App\Mcp\Tools\CreateContentItemTool;
use App\Mcp\Tools\CreateMarketingStrategyTool;
use App\Mcp\Tools\CreateStrategyTool;
use App\Mcp\Tools\CreateWorkItemTool;
use App\Mcp\Tools\DelegateAgentTool;
use App\Mcp\Tools\ExecuteAgentTool;
use App\Mcp\Tools\GenerateFinancialReportTool;
use App\Mcp\Tools\MarkContentPublicationReadyTool;
use App\Mcp\Tools\PlanMarketingTool;
use App\Mcp\Tools\PublishContentTool;
use App\Mcp\Tools\RecordMemoryTool;
use App\Mcp\Tools\RetrieveEnterpriseContextTool;
use App\Mcp\Tools\RetrieveKnowledgeTool;
use App\Mcp\Tools\RetrieveMemoryTool;
use App\Mcp\Tools\SubmitContentForReviewTool;
use App\Mcp\Tools\UpdateContentItemTool;
use App\Mcp\Tools\UpdateStrategyTool;
use App\Mcp\Tools\UpdateWorkItemTool;
use App\Operations\AnalyzeBusinessContext;
use App\Operations\ApprovalRequestCreate;
use App\Operations\CampaignLifecycle;
use App\Operations\ContentSeriesLifecycle;
use App\Operations\CreateAgentAssignment;
use App\Operations\CreateContentItem;
use App\Operations\CreateKnowledgeIndex;
use App\Operations\CreateKnowledgeItem;
use App\Operations\CreateKnowledgeUnit;
use App\Operations\CreateMarketingStrategy;
use App\Operations\CreateStrategy;
use App\Operations\CreateWorkItem;
use App\Operations\DelegateAgent;
use App\Operations\EnterpriseContextCreate;
use App\Operations\EnterpriseContextRetrieve;
use App\Operations\EnterpriseCreate;
use App\Operations\ExecuteAgent;
use App\Operations\GenerateFinancialReport;
use App\Operations\MarkContentPublicationReady;
use App\Operations\MarketingAudienceArchive;
use App\Operations\MarketingAudienceCreate;
use App\Operations\MarketingAudienceUpdate;
use App\Operations\MarketingCampaignCreate;
use App\Operations\MarketingCampaignUpdate;
use App\Operations\MarketingChannelArchive;
use App\Operations\MarketingChannelCreate;
use App\Operations\MarketingChannelUpdate;
use App\Operations\MarketingContentSeriesCreate;
use App\Operations\MarketingContentSeriesUpdate;
use App\Operations\MarketingStrategyArchive;
use App\Operations\ObjectiveCreate;
use App\Operations\ObjectiveUpdate;
use App\Operations\PlanMarketing;
use App\Operations\ProjectCreate;
use App\Operations\ProjectUpdate;
use App\Operations\PublishContent;
use App\Operations\RecordMemory;
use App\Operations\RetrieveKnowledge;
use App\Operations\RetrieveMemory;
use App\Operations\SocialAccountConnect;
use App\Operations\SocialAccountDisconnect;
use App\Operations\SocialAccountUpdate;
use App\Operations\SubmitContentForReview;
use App\Operations\TransitionAgentAssignment;
use App\Operations\UpdateAgentAssignment;
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
                'agent.execute',
                ExecuteAgent::class,
                ExecuteAgentTool::class,
                [
                    'enterprise_id' => 'integer|required',
                    'agent_assignment_id' => 'integer|required',
                    'prompt' => 'string|required',
                    'mode' => 'string|in:interactive,autonomous|required',
                    'capability_requests' => 'array|nullable',
                    'capability_requests.*.capability' => 'string|required',
                    'capability_requests.*.expert_slug' => 'string|required',
                    'capability_requests.*.step' => 'integer|min:1|nullable',
                    'capability_requests.*.target_context' => 'object|nullable',
                    'capability_requests.*.input_payload' => 'object|nullable',
                    'capability_requests.*.approval_request_id' => 'integer|min:1|nullable',
                    'capability_requests.*.idempotency_key' => 'string|min:1|max:128|nullable',
                    'target_context' => 'object|nullable',
                    'expert_slugs' => 'array|nullable',
                    'options' => 'object|nullable',
                    'correlation_id' => 'string|nullable',
                    'idempotency_key' => 'string|nullable',
                ],
                ['success' => 'boolean', 'result' => 'agent-execution'],
                'McpCapabilityAuthorizer::authorizeCapability + AgentExecutionService',
                'none',
            ),
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
                'none',
            ),
            $this->definition(
                'business.analysis',
                AnalyzeBusinessContext::class,
                AnalyzeBusinessContextTool::class,
                ['enterprise_id' => 'integer|required', 'target_context' => 'object|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'object'],
                'ExpertCapabilityService + capability authorization',
                'none',
            ),
            $this->definition(
                'finance.report.generate',
                GenerateFinancialReport::class,
                GenerateFinancialReportTool::class,
                ['enterprise_id' => 'integer|required', 'financial_period_id' => 'integer|required', 'financial_account_id' => 'integer|nullable', 'transaction_category_id' => 'integer|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'financial-report'],
                'McpCapabilityAuthorizer::authorizeMutation + Finance policy',
                'none',
            ),
            $this->definition(
                'marketing.strategy.create',
                CreateMarketingStrategy::class,
                CreateMarketingStrategyTool::class,
                ['enterprise_id' => 'integer|required', 'name' => 'string|required', 'description' => 'string|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + MarketingStrategy policy',
                'none',
            ),
            $this->definition(
                'marketing.content.create',
                CreateContentItem::class,
                CreateContentItemTool::class,
                ['enterprise_id' => 'integer|required', 'campaign_id' => 'integer|required', 'content_series_id' => 'integer|nullable', 'channel_id' => 'integer|nullable', 'audience_id' => 'integer|nullable', 'title' => 'string|required', 'body' => 'string|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'content-item'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentItem policy',
                'none',
            ),
            $this->definition(
                'marketing.content.update',
                UpdateContentItem::class,
                UpdateContentItemTool::class,
                ['content_item_id' => 'integer|required', 'title' => 'string|nullable', 'body' => 'string|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'content-item'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentItem policy',
                'none',
            ),
            $this->definition(
                'marketing.content.review',
                SubmitContentForReview::class,
                SubmitContentForReviewTool::class,
                ['content_item_id' => 'integer|required', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'content-item'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentItem policy',
                'none',
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
                'none',
            ),
            $this->definition(
                'publication.publish',
                PublishContent::class,
                PublishContentTool::class,
                ['content_item_id' => 'integer|required', 'social_account_id' => 'integer|required', 'scheduled_at' => 'date|required', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'publication'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentItem policy',
                'required',
            ),
            $this->definition(
                'strategy.create',
                CreateStrategy::class,
                CreateStrategyTool::class,
                ['objective_id' => 'integer|required', 'name' => 'string|required', 'description' => 'string|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'strategy'],
                'McpCapabilityAuthorizer::authorizeMutation + Strategy policy',
                'none',
            ),
            $this->definition(
                'strategy.update',
                UpdateStrategy::class,
                UpdateStrategyTool::class,
                ['strategy_id' => 'integer|required', 'name' => 'string|nullable', 'description' => 'string|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'strategy'],
                'McpCapabilityAuthorizer::authorizeMutation + Strategy policy',
                'none',
            ),
            $this->definition(
                'work.item.create',
                CreateWorkItem::class,
                CreateWorkItemTool::class,
                ['enterprise_id' => 'integer|required', 'name' => 'string|required', 'description' => 'string|nullable', 'status' => 'string|nullable', 'project_id' => 'integer|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'work-item'],
                'McpCapabilityAuthorizer::authorizeMutation + WorkItem policy',
                'none',
            ),
            $this->definition(
                'work.item.update',
                UpdateWorkItem::class,
                UpdateWorkItemTool::class,
                ['work_item_id' => 'integer|required', 'name' => 'string|nullable', 'description' => 'string|nullable', 'status' => 'string|nullable', 'project_id' => 'integer|nullable', 'agent_assignment_id' => 'integer|nullable', 'agent_execution_id' => 'integer|nullable', 'approval_request_id' => 'integer|nullable'],
                ['success' => 'boolean', 'result' => 'work-item'],
                'McpCapabilityAuthorizer::authorizeMutation + WorkItem policy',
                'none',
            ),
            $this->definition(
                'enterprise.create',
                EnterpriseCreate::class,
                \App\Mcp\Tools\CreateEnterpriseTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'none',
                'none',
            ),
            $this->definition(
                'approval.request',
                ApprovalRequestCreate::class,
                \App\Mcp\Tools\RequestApprovalTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'assignment authorization',
                'required',
            ),
            $this->definition(
                'marketing.audience.create',
                MarketingAudienceCreate::class,
                \App\Mcp\Tools\CreateAudienceTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Audience policy',
                'none',
            ),
            $this->definition(
                'marketing.audience.update',
                MarketingAudienceUpdate::class,
                \App\Mcp\Tools\UpdateAudienceTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Audience policy',
                'none',
            ),
            $this->definition(
                'marketing.audience.archive',
                MarketingAudienceArchive::class,
                \App\Mcp\Tools\ArchiveAudienceTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Audience policy',
                'none',
            ),
            $this->definition(
                'marketing.campaign.create',
                MarketingCampaignCreate::class,
                \App\Mcp\Tools\CreateCampaignTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Campaign policy',
                'none',
            ),
            $this->definition(
                'marketing.campaign.update',
                MarketingCampaignUpdate::class,
                \App\Mcp\Tools\UpdateCampaignTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Campaign policy',
                'none',
            ),
            $this->definition(
                'marketing.campaign.lifecycle',
                CampaignLifecycle::class,
                \App\Mcp\Tools\TransitionCampaignTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Campaign policy',
                'none',
            ),
            $this->definition(
                'marketing.channel.create',
                MarketingChannelCreate::class,
                \App\Mcp\Tools\CreateChannelTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Channel policy',
                'none',
            ),
            $this->definition(
                'marketing.channel.update',
                MarketingChannelUpdate::class,
                \App\Mcp\Tools\UpdateChannelTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Channel policy',
                'none',
            ),
            $this->definition(
                'marketing.channel.archive',
                MarketingChannelArchive::class,
                \App\Mcp\Tools\ArchiveChannelTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Channel policy',
                'none',
            ),
            $this->definition(
                'marketing.content-series.create',
                MarketingContentSeriesCreate::class,
                \App\Mcp\Tools\CreateContentSeriesTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentSeries policy',
                'none',
            ),
            $this->definition(
                'marketing.content-series.update',
                MarketingContentSeriesUpdate::class,
                \App\Mcp\Tools\UpdateContentSeriesTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentSeries policy',
                'none',
            ),
            $this->definition(
                'marketing.content-series.lifecycle',
                ContentSeriesLifecycle::class,
                \App\Mcp\Tools\TransitionContentSeriesTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + ContentSeries policy',
                'none',
            ),
            $this->definition(
                'enterprise.context.create',
                EnterpriseContextCreate::class,
                \App\Mcp\Tools\CreateEnterpriseContextTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + EnterpriseContext policy',
                'none',
            ),
            $this->definition(
                'enterprise.context.retrieve',
                EnterpriseContextRetrieve::class,
                RetrieveEnterpriseContextTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'EnterprisePolicy::view + EnterpriseContextService authorization',
                'none',
            ),
            $this->definition(
                'marketing.objective.create',
                ObjectiveCreate::class,
                \App\Mcp\Tools\CreateObjectiveTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Objective policy',
                'none',
            ),
            $this->definition(
                'marketing.objective.update',
                ObjectiveUpdate::class,
                \App\Mcp\Tools\UpdateObjectiveTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Objective policy',
                'none',
            ),
            $this->definition(
                'marketing.project.create',
                ProjectCreate::class,
                \App\Mcp\Tools\CreateProjectTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Project policy',
                'none',
            ),
            $this->definition(
                'marketing.project.update',
                ProjectUpdate::class,
                \App\Mcp\Tools\UpdateProjectTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + Project policy',
                'none',
            ),
            $this->definition(
                'marketing.social-account.connect',
                SocialAccountConnect::class,
                \App\Mcp\Tools\ConnectSocialAccountTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + SocialAccount policy',
                'none',
            ),
            $this->definition(
                'marketing.social-account.update',
                SocialAccountUpdate::class,
                \App\Mcp\Tools\UpdateSocialAccountTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + SocialAccount policy',
                'none',
            ),
            $this->definition(
                'marketing.social-account.disconnect',
                SocialAccountDisconnect::class,
                \App\Mcp\Tools\DisconnectSocialAccountTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + SocialAccount policy',
                'none',
            ),
            $this->definition(
                'marketing.strategy.archive',
                MarketingStrategyArchive::class,
                \App\Mcp\Tools\ArchiveMarketingStrategyTool::class,
                ['input' => 'object'],
                ['success' => 'boolean', 'result' => 'object'],
                'McpCapabilityAuthorizer::authorizeMutation + MarketingStrategy policy',
                'none',
            ),
            $this->definition(
                'memory.retrieve',
                RetrieveMemory::class,
                RetrieveMemoryTool::class,
                [
                    'enterprise_id' => 'integer|required',
                    'agent_descriptor_id' => 'integer|required',
                    'agent_assignment_id' => 'integer|nullable',
                    'topic' => 'string|nullable',
                    'relevant_after' => 'string|nullable',
                    'episodic_limit' => 'integer|nullable',
                    'semantic_status' => 'string|nullable',
                    'semantic_limit' => 'integer|nullable',
                ],
                ['success' => 'boolean', 'result' => 'memory-retrieval'],
                'McpCapabilityAuthorizer::authorizeCapability + AgentMemoryPolicy',
                'none',
            ),
            $this->definition(
                'memory.record',
                RecordMemory::class,
                RecordMemoryTool::class,
                [
                    'enterprise_id' => 'integer|required',
                    'persist' => 'boolean|required',
                    'type' => 'string|required',
                    'source_execution_id' => 'integer|required',
                    'agent_descriptor_id' => 'integer|nullable',
                ],
                ['success' => 'boolean', 'result' => 'memory-record'],
                'McpCapabilityAuthorizer::authorizeCapability + AgentMemoryPolicy',
                'none',
            ),
            $this->definition(
                'agent.assignment.create',
                CreateAgentAssignment::class,
                \App\Mcp\Tools\CreateAgentAssignmentTool::class,
                ['enterprise_id' => 'integer|required', 'agent_descriptor_id' => 'integer|required', 'objective' => 'string|nullable', 'requirements' => 'object|nullable', 'context' => 'object|nullable', 'status' => 'string|nullable', 'correlation_id' => 'string|nullable', 'idempotency_key' => 'string|nullable'],
                ['success' => 'boolean', 'result' => 'agent-assignment'],
                'Enterprise policy + AgentAssignment createForAgentAssignment authorization',
                'none',
            ),
            $this->definition(
                'agent.assignment.update',
                UpdateAgentAssignment::class,
                \App\Mcp\Tools\UpdateAgentAssignmentTool::class,
                ['enterprise_id' => 'integer|required', 'agent_assignment_id' => 'integer|required', 'agent_descriptor_id' => 'integer|nullable', 'objective' => 'string|nullable', 'requirements' => 'object|nullable', 'context' => 'object|nullable', 'correlation_id' => 'string|nullable'],
                ['success' => 'boolean', 'result' => 'agent-assignment'],
                'Enterprise policy + AgentAssignment update authorization',
                'none',
            ),
            $this->definition(
                'agent.assignment.transition',
                TransitionAgentAssignment::class,
                \App\Mcp\Tools\TransitionAgentAssignmentTool::class,
                ['enterprise_id' => 'integer|required', 'agent_assignment_id' => 'integer|required', 'status' => 'string|required'],
                ['success' => 'boolean', 'result' => 'agent-assignment'],
                'Enterprise policy + AgentAssignment lifecycle authorization',
                'none',
            ),
            $this->definition(
                'knowledge.item.create',
                CreateKnowledgeItem::class,
                \App\Mcp\Tools\CreateKnowledgeItemTool::class,
                ['enterprise_id' => 'integer|required', 'title' => 'string|required', 'type' => 'string|nullable', 'summary' => 'string|nullable', 'content' => 'string|required', 'context_snapshot' => 'object|nullable', 'correlation_id' => 'string|nullable', 'idempotency_key' => 'string|nullable'],
                ['success' => 'boolean', 'result' => 'object'],
                'Enterprise policy + KnowledgeItem createForEnterprise authorization',
                'none',
            ),
            $this->definition(
                'knowledge.index.create',
                CreateKnowledgeIndex::class,
                \App\Mcp\Tools\CreateKnowledgeIndexTool::class,
                ['enterprise_id' => 'integer|required', 'knowledge_item_id' => 'integer|required', 'correlation_id' => 'string|nullable', 'idempotency_key' => 'string|nullable'],
                ['success' => 'boolean', 'result' => 'object'],
                'Enterprise policy + KnowledgeItem authorization',
                'none',
            ),
            $this->definition(
                'knowledge.unit.create',
                CreateKnowledgeUnit::class,
                \App\Mcp\Tools\CreateKnowledgeUnitTool::class,
                ['enterprise_id' => 'integer|required', 'knowledge_item_id' => 'integer|required', 'unit_key' => 'string|required', 'correlation_id' => 'string|nullable', 'idempotency_key' => 'string|nullable'],
                ['success' => 'boolean', 'result' => 'object'],
                'Enterprise policy + KnowledgeItem authorization',
                'none',
            ),
            $this->definition(
                'knowledge.retrieve',
                RetrieveKnowledge::class,
                RetrieveKnowledgeTool::class,
                [
                    'enterprise_id' => 'integer|required',
                    'query' => 'string|nullable',
                    'objective' => 'string|nullable',
                    'mode' => 'string|required',
                    'limit' => 'integer|nullable',
                    'minimum_relevance' => 'number|nullable',
                    'correlation_id' => 'string|nullable',
                    'agent_assignment_id' => 'integer|nullable',
                    'agent_execution_id' => 'integer|nullable',
                ],
                ['success' => 'boolean', 'result' => 'knowledge-retrieval'],
                'McpCapabilityAuthorizer::authorizeCapability + Enterprise policy',
                'none',
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
            failureContract: CapabilityFailureContract::standard(),
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