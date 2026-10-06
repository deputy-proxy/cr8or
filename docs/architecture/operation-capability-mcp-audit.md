# CR8OR Operation -> Capability -> MCP Tool Audit

Issue #373. Repository: deputy-proxy/cr8or. Audit date: 2026-10-03.

> **Historical baseline:** This document records the pre-#383 inventory state and is retained for historical traceability. Its execution-path findings are superseded by `docs/architecture/unified-execution-audit-2026-10-03.md`, which reflects the current implementation after the #383 remediation.

## Executive summary

- MCP Tool files: 130.
- Abstract/framework Tool classes: 8.
- Concrete MCP Tool classes: 122.
- CapabilityRegistry definitions: 59.
- Operation files: 82.
- The registry already enforces unique Capability identifiers, Operation mappings, MCP Tool identifiers and Tool classes for its registered surface.
- The current registry mixes lifecycle Tools with business Tools.
- Several registered Tools still execute Operations directly instead of entering CapabilityInvocationService.
- Six concrete mutation Tools have no CapabilityRegistry mapping.


## Post-#383 current additions

The current registry additionally governs these formerly unmapped mutating resource Tools:

| Capability | Operation | MCP Tool | Tool class |
|---|---|---|---|
| knowledge.unit.archive | ArchiveKnowledgeUnit | archive-knowledge-unit | ArchiveKnowledgeUnitTool |
| knowledge.index.update | UpdateKnowledgeIndex | update-knowledge-index | UpdateKnowledgeIndexTool |
| knowledge.unit.update | UpdateKnowledgeUnit | update-knowledge-unit | UpdateKnowledgeUnitTool |
| memory.create | CreateMemory | create-memory | CreateMemoryTool |
| memory.update | UpdateMemory | update-memory | UpdateMemoryTool |
| memory.archive | ArchiveMemory | archive-memory | ArchiveMemoryTool |

The post-#383 execution path for these mapped resource mutations is `MCP Tool → CapabilityInvocationService → Operation`.

## Complete Capability registry matrix

| Capability | Operation | MCP Tool | Tool class | Category | Workflow | Direct | Entry | Authorization | Approval | Correlation | Idempotency | Failure |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| workflow.create | CreateWorkflow | mcp_workflow_create | CreateWorkflowTool | lifecycle | no | yes | CapabilityInvocationService | WorkflowPolicy::create + enterprise scope | none | no | no | standard |
| workflow.duplicate | DuplicateWorkflow | mcp_workflow_duplicate | DuplicateWorkflowTool | lifecycle | no | yes | CapabilityInvocationService | WorkflowPolicy::createForEnterprise + source workflow scope | none | no | no | standard |
| workflow.update | UpdateWorkflow | mcp_workflow_update | UpdateWorkflowTool | lifecycle | no | yes | CapabilityInvocationService | WorkflowPolicy::update + enterprise scope | none | no | no | standard |
| workflow.publish | PublishWorkflow | mcp_workflow_publish | PublishWorkflowTool | lifecycle | no | yes | CapabilityInvocationService | WorkflowPolicy::view + immutable version publication | none | no | yes | standard |
| workflow.discover | DiscoverWorkflows | list-workflows | ListWorkflowsTool | business | yes | yes | CapabilityInvocationService | Enterprise view authorization | none | no | no | standard |
| workflow.execute | StartWorkflow | mcp_workflow_execute | StartWorkflowTool | lifecycle | no | yes | CapabilityInvocationService | Published Workflow view authorization | none | yes | yes | standard |
| workflow.inspect | InspectWorkflowExecution | mcp_workflow_inspect | GetWorkflowExecutionTool | lifecycle | no | yes | CapabilityInvocationService | WorkflowExecution enterprise/workflow authorization | none | no | no | standard |
| workflow.resume | ResumeWorkflowExecution | mcp_workflow_resume | ResumeWorkflowExecutionTool | lifecycle | no | yes | CapabilityInvocationService | WorkflowExecution enterprise/workflow authorization | none | no | no | standard |
| agent.continue | ContinueAgentExecution | mcp_agent_continue | ContinueAgentExecutionTool | lifecycle | no | yes | Operation direct | InteractiveContinuationService + governed Capability execution | none | no | yes | standard |
| agent.execute | ExecuteAgent | mcp_agent_execute | ExecuteAgentTool | lifecycle | no | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeCapability + AgentExecutionService | none | yes | yes | standard |
| agent.delegate | DelegateAgent | mcp_agent_delegate | DelegateAgentTool | lifecycle | no | yes | CapabilityInvocationService | delegation-service + capability authorization | none | yes | yes | standard |
| business.analysis | AnalyzeBusinessContext | analyze-business-context | AnalyzeBusinessContextTool | business | yes | yes | CapabilityInvocationService | ExpertCapabilityService + capability authorization | none | no | no | standard |
| finance.report.generate | GenerateFinancialReport | generate-financial-report | GenerateFinancialReportTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + Finance policy | none | no | no | standard |
| marketing.strategy.create | CreateMarketingStrategy | create-marketing-strategy | CreateMarketingStrategyTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + MarketingStrategy policy | none | no | no | standard |
| marketing.strategy.section.define | DefineMarketingStrategySection | define-marketing-strategy-section | DefineMarketingStrategySectionTool | business | yes | yes | CapabilityInvocationService | Workflow Expert declaration + Enterprise view authorization | none | no | no | standard |
| marketing.content.create | CreateContentItem | create-content-item | CreateContentItemTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + ContentItem policy | none | no | no | standard |
| marketing.asset.create | CreatePlannedAsset | create-asset | CreateAssetTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Asset policy | none | no | no | standard |
| marketing.graph.verify | VerifyMarketingGraph | verify-marketing-graph | VerifyMarketingGraphTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + Enterprise view policy | none | no | no | standard |
| marketing.script.create | CreateScript | create-script | CreateScriptTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Script policy | none | no | no | standard |
| marketing.content.update | UpdateContentItem | update-content-item | UpdateContentItemTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + ContentItem policy | none | no | no | standard |
| marketing.content.review | SubmitContentForReview | submit-content-for-review | SubmitContentForReviewTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + ContentItem policy | none | no | no | standard |
| marketing.content.publication-ready | MarkContentPublicationReady | mark-content-publication-ready | MarkContentPublicationReadyTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + explicit approval match | required | no | no | standard |
| marketing.plan | PlanMarketing | plan-marketing | PlanMarketingTool | business | yes | yes | CapabilityInvocationService | ExpertCapabilityService + capability authorization | none | no | no | standard |
| publication.publish | PublishContent | publish-content | PublishContentTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + ContentItem policy | required | no | no | standard |
| strategy.create | CreateStrategy | create-strategy | CreateStrategyTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + Strategy policy | none | no | no | standard |
| strategy.update | UpdateStrategy | update-strategy | UpdateStrategyTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + Strategy policy | none | no | no | standard |
| work.item.create | CreateWorkItem | create-work-item | CreateWorkItemTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + WorkItem policy | none | no | no | standard |
| work.item.update | UpdateWorkItem | update-work-item | UpdateWorkItemTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeMutation + WorkItem policy | none | no | no | standard |
| enterprise.create | EnterpriseCreate | create-enterprise | CreateEnterpriseTool | business | yes | yes | Operation direct | none | none | no | no | standard |
| approval.request | ApprovalRequestCreate | mcp_agent_approval_request | RequestApprovalTool | business | yes | yes | Operation direct | assignment authorization | required | no | no | standard |
| marketing.audience.create | MarketingAudienceCreate | create-audience | CreateAudienceTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Audience policy | none | no | no | standard |
| marketing.audience.update | MarketingAudienceUpdate | update-audience | UpdateAudienceTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Audience policy | none | no | no | standard |
| marketing.audience.archive | MarketingAudienceArchive | archive-audience | ArchiveAudienceTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Audience policy | none | no | no | standard |
| marketing.campaign.create | MarketingCampaignCreate | create-campaign | CreateCampaignTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Campaign policy | none | no | no | standard |
| marketing.campaign.update | MarketingCampaignUpdate | update-campaign | UpdateCampaignTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Campaign policy | none | no | no | standard |
| marketing.campaign.lifecycle | CampaignLifecycle | transition-campaign | TransitionCampaignTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Campaign policy | none | no | no | standard |
| marketing.channel.create | MarketingChannelCreate | create-channel | CreateChannelTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Channel policy | none | no | no | standard |
| marketing.channel.update | MarketingChannelUpdate | update-channel | UpdateChannelTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Channel policy | none | no | no | standard |
| marketing.channel.archive | MarketingChannelArchive | archive-channel | ArchiveChannelTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Channel policy | none | no | no | standard |
| marketing.content-series.create | MarketingContentSeriesCreate | create-content-series | CreateContentSeriesTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + ContentSeries policy | none | no | no | standard |
| marketing.content-series.update | MarketingContentSeriesUpdate | update-content-series | UpdateContentSeriesTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + ContentSeries policy | none | no | no | standard |
| marketing.content-series.lifecycle | ContentSeriesLifecycle | transition-content-series | TransitionContentSeriesTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + ContentSeries policy | none | no | no | standard |
| enterprise.context.create | EnterpriseContextCreate | create-enterprise-context | CreateEnterpriseContextTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + EnterpriseContext policy | none | no | no | standard |
| enterprise.context.retrieve | EnterpriseContextRetrieve | retrieve-enterprise-context | RetrieveEnterpriseContextTool | business | yes | yes | CapabilityInvocationService | EnterprisePolicy::view + EnterpriseContextService authorization | none | no | no | standard |
| marketing.objective.create | ObjectiveCreate | create-objective | CreateObjectiveTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Objective policy | none | no | no | standard |
| marketing.objective.update | ObjectiveUpdate | update-objective | UpdateObjectiveTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Objective policy | none | no | no | standard |
| marketing.project.create | ProjectCreate | create-project | CreateProjectTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Project policy | none | no | no | standard |
| marketing.project.update | ProjectUpdate | update-project | UpdateProjectTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + Project policy | none | no | no | standard |
| marketing.social-account.connect | SocialAccountConnect | connect-social-account | ConnectSocialAccountTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + SocialAccount policy | none | no | no | standard |
| marketing.social-account.update | SocialAccountUpdate | update-social-account | UpdateSocialAccountTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + SocialAccount policy | none | no | no | standard |
| marketing.social-account.disconnect | SocialAccountDisconnect | disconnect-social-account | DisconnectSocialAccountTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + SocialAccount policy | none | no | no | standard |
| marketing.strategy.archive | MarketingStrategyArchive | archive-marketing-strategy | ArchiveMarketingStrategyTool | business | yes | yes | Operation direct | McpCapabilityAuthorizer::authorizeMutation + MarketingStrategy policy | none | no | no | standard |
| memory.retrieve | RetrieveMemory | retrieve-memory | RetrieveMemoryTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeCapability + AgentMemoryPolicy | none | no | no | standard |
| memory.record | RecordMemory | record-memory | RecordMemoryTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeCapability + AgentMemoryPolicy | none | no | no | standard |
| agent.assignment.create | CreateAgentAssignment | mcp_agent_assignment_create | CreateAgentAssignmentTool | lifecycle | no | yes | Operation direct | Enterprise policy + AgentAssignment createForAgentAssignment authorization | none | yes | yes | standard |
| agent.assignment.update | UpdateAgentAssignment | mcp_agent_assignment_update | UpdateAgentAssignmentTool | lifecycle | no | yes | Operation direct | Enterprise policy + AgentAssignment update authorization | none | yes | no | standard |
| agent.assignment.transition | TransitionAgentAssignment | mcp_agent_assignment_transition | TransitionAgentAssignmentTool | lifecycle | no | yes | Operation direct | Enterprise policy + AgentAssignment lifecycle authorization | none | no | no | standard |
| knowledge.item.create | CreateKnowledgeItem | create-knowledge-item | CreateKnowledgeItemTool | business | yes | yes | CapabilityInvocationService | Enterprise policy + KnowledgeItem createForEnterprise authorization | none | yes | yes | standard |
| knowledge.index.create | CreateKnowledgeIndex | create-knowledge-index | CreateKnowledgeIndexTool | business | yes | yes | CapabilityInvocationService | Enterprise policy + KnowledgeItem authorization | none | yes | yes | standard |
| knowledge.unit.create | CreateKnowledgeUnit | create-knowledge-unit | CreateKnowledgeUnitTool | business | yes | yes | CapabilityInvocationService | Enterprise policy + KnowledgeItem authorization | none | yes | yes | standard |
| knowledge.retrieve | RetrieveKnowledge | retrieve-knowledge | RetrieveKnowledgeTool | business | yes | yes | CapabilityInvocationService | McpCapabilityAuthorizer::authorizeCapability + Enterprise policy | none | yes | no | standard |


## Complete concrete MCP Tool classification

| Tool class | Parent | Classification | Registry | Execution path |
|---|---|---|---|---|
| AnalyzeBusinessContextTool | GovernedCapabilityTool | governed business | business.analysis | CapabilityInvocationService |
| ArchiveAudienceTool | DomainMutationTool | governed business | marketing.audience.archive | Operation direct |
| ArchiveChannelTool | DomainMutationTool | governed business | marketing.channel.archive | Operation direct |
| ArchiveKnowledgeUnitTool | KnowledgeResourceTool | invalid/incomplete mapping | - | Operation direct |
| ArchiveMarketingStrategyTool | DomainMutationTool | governed business | marketing.strategy.archive | Operation direct |
| ArchiveMemoryTool | MemoryResourceTool | invalid/incomplete mapping | - | Operation direct |
| CancelAgentExecutionTool | AgentExecutionResourceTool | lifecycle | - | Operation direct |
| ConnectSocialAccountTool | DomainMutationTool | governed business | marketing.social-account.connect | Operation direct |
| ContinueAgentExecutionTool | AgentExecutionResourceTool | lifecycle | agent.continue | Operation direct |
| CreateAgentAssignmentTool | AgentAssignmentResourceTool | lifecycle | agent.assignment.create | Operation direct |
| CreateAgentExecutionTool | AgentExecutionResourceTool | lifecycle | - | Operation direct |
| CreateAssetTool | DomainMutationTool | governed business | marketing.asset.create | Operation direct |
| CreateAudienceTool | DomainMutationTool | governed business | marketing.audience.create | Operation direct |
| CreateCampaignTool | DomainMutationTool | governed business | marketing.campaign.create | Operation direct |
| CreateChannelTool | DomainMutationTool | governed business | marketing.channel.create | Operation direct |
| CreateConnectionTool | DomainMutationTool | governed business | integration.connection.create | Operation direct |
| CreateContentItemTool | GovernedCapabilityTool | governed business | marketing.content.create | CapabilityInvocationService |
| CreateContentSeriesTool | DomainMutationTool | governed business | marketing.content-series.create | Operation direct |
| CreateEnterpriseContextTool | DomainMutationTool | governed business | enterprise.context.create | Operation direct |
| CreateEnterpriseTool | AuthorizedTool | governed business | enterprise.create | Operation direct |
| CreateKnowledgeIndexTool | GovernedCapabilityTool | governed business | knowledge.index.create | CapabilityInvocationService |
| CreateKnowledgeItemTool | GovernedCapabilityTool | governed business | knowledge.item.create | CapabilityInvocationService |
| CreateKnowledgeUnitTool | GovernedCapabilityTool | governed business | knowledge.unit.create | CapabilityInvocationService |
| CreateMarketingStrategyTool | GovernedCapabilityTool | governed business | marketing.strategy.create | CapabilityInvocationService |
| CreateMemoryTool | MemoryResourceTool | invalid/incomplete mapping | - | Operation direct |
| CreateObjectiveTool | DomainMutationTool | governed business | marketing.objective.create | Operation direct |
| CreateProjectTool | DomainMutationTool | governed business | marketing.project.create | Operation direct |
| CreateScriptTool | DomainMutationTool | governed business | marketing.script.create | Operation direct |
| CreateStrategyTool | GovernedCapabilityTool | governed business | strategy.create | CapabilityInvocationService |
| CreateWorkItemTool | GovernedCapabilityTool | governed business | work.item.create | CapabilityInvocationService |
| CreateWorkflowTool | GovernedCapabilityTool | lifecycle | workflow.create | CapabilityInvocationService |
| UpdateWorkflowTool | GovernedCapabilityTool | lifecycle | workflow.update | CapabilityInvocationService |
| DefineMarketingStrategySectionTool | GovernedCapabilityTool | governed business | marketing.strategy.section.define | CapabilityInvocationService |
| DelegateAgentTool | GovernedCapabilityTool | lifecycle | agent.delegate | CapabilityInvocationService |
| DisconnectSocialAccountTool | DomainMutationTool | governed business | marketing.social-account.disconnect | Operation direct |
| DiscoveryGetTool | AuthorizedTool | read/query | - | n/a |
| DiscoveryListTool | AuthorizedTool | read/query | - | n/a |
| ExecuteAgentTool | GovernedCapabilityTool | lifecycle | agent.execute | CapabilityInvocationService |
| GenerateFinancialReportTool | GovernedCapabilityTool | governed business | finance.report.generate | CapabilityInvocationService |
| GetAgentAssignmentTool | AgentAssignmentResourceTool | read/query | - | Operation direct |
| GetAgentDelegationTool | AgentAssignmentResourceTool | read/query | - | Operation direct |
| GetAgentDescriptorTool | DiscoveryGetTool | read/query | - | n/a |
| GetApprovalRequestTool | DiscoveryGetTool | read/query | - | n/a |
| GetAudienceTool | DiscoveryGetTool | read/query | - | n/a |
| GetCampaignTool | DiscoveryGetTool | read/query | - | n/a |
| GetCapabilityTool | AuthorizedTool | read/query | - | n/a |
| GetChannelTool | DiscoveryGetTool | read/query | - | n/a |
| GetContentItemTool | DiscoveryGetTool | read/query | - | n/a |
| GetContentSeriesTool | DiscoveryGetTool | read/query | - | n/a |
| GetEnterpriseTool | DiscoveryGetTool | read/query | - | n/a |
| GetExecutionTool | AgentExecutionResourceTool | read/query | - | Operation direct |
| GetExpertDescriptorTool | DiscoveryGetTool | read/query | - | n/a |
| GetGoalTool | DiscoveryGetTool | read/query | - | n/a |
| GetInitiativeTool | DiscoveryGetTool | read/query | - | n/a |
| GetKnowledgeIndexTool | KnowledgeResourceTool | read/query | - | Operation direct |
| GetKnowledgeUnitTool | KnowledgeResourceTool | read/query | - | Operation direct |
| GetKpiTool | DiscoveryGetTool | read/query | - | n/a |
| GetMarketingStrategyTool | DiscoveryGetTool | read/query | - | n/a |
| GetMemoryTool | MemoryResourceTool | read/query | - | Operation direct |
| GetObjectiveTool | DiscoveryGetTool | read/query | - | n/a |
| GetPlanTool | DiscoveryGetTool | read/query | - | n/a |
| GetProjectTool | DiscoveryGetTool | read/query | - | n/a |
| GetSocialAccountTool | DiscoveryGetTool | read/query | - | n/a |
| GetStrategyTool | DiscoveryGetTool | read/query | - | n/a |
| GetWorkItemTool | DiscoveryGetTool | read/query | - | n/a |
| GetWorkflowExecutionTool | GovernedCapabilityTool | lifecycle | workflow.inspect | CapabilityInvocationService |
| ListAgentAssignmentsTool | AgentAssignmentResourceTool | read/query | - | Operation direct |
| ListAgentDelegationsTool | AgentAssignmentResourceTool | read/query | - | Operation direct |
| ListAgentDescriptorTool | DiscoveryListTool | read/query | - | n/a |
| ListApprovalRequestTool | DiscoveryListTool | read/query | - | n/a |
| ListAudienceTool | DiscoveryListTool | read/query | - | n/a |
| ListCampaignTool | DiscoveryListTool | read/query | - | n/a |
| ListCapabilitiesTool | AuthorizedTool | read/query | - | n/a |
| ListChannelTool | DiscoveryListTool | read/query | - | n/a |
| ListContentItemTool | DiscoveryListTool | read/query | - | n/a |
| ListContentSeriesTool | DiscoveryListTool | read/query | - | n/a |
| ListEnterpriseTool | DiscoveryListTool | read/query | - | n/a |
| ListExecutionTool | AgentExecutionResourceTool | read/query | - | Operation direct |
| ListExpertDescriptorTool | DiscoveryListTool | read/query | - | n/a |
| ListGoalTool | DiscoveryListTool | read/query | - | n/a |
| ListInitiativeTool | DiscoveryListTool | read/query | - | n/a |
| ListKnowledgeIndexesTool | KnowledgeResourceTool | read/query | - | Operation direct |
| ListKnowledgeUnitsTool | KnowledgeResourceTool | read/query | - | Operation direct |
| ListKpiTool | DiscoveryListTool | read/query | - | n/a |
| ListMarketingStrategyTool | DiscoveryListTool | read/query | - | n/a |
| ListMemoryTool | MemoryResourceTool | read/query | - | Operation direct |
| ListObjectiveTool | DiscoveryListTool | read/query | - | n/a |
| ListPlanTool | DiscoveryListTool | read/query | - | n/a |
| ListProjectTool | DiscoveryListTool | read/query | - | n/a |
| ListSocialAccountTool | DiscoveryListTool | read/query | - | n/a |
| ListStrategyTool | DiscoveryListTool | read/query | - | n/a |
| ListWorkItemTool | DiscoveryListTool | read/query | - | n/a |
| ListWorkflowsTool | GovernedCapabilityTool | read/query | workflow.discover | CapabilityInvocationService |
| MarkContentPublicationReadyTool | GovernedCapabilityTool | governed business | marketing.content.publication-ready | CapabilityInvocationService |
| PlanMarketingTool | GovernedCapabilityTool | governed business | marketing.plan | CapabilityInvocationService |
| PublishContentTool | GovernedCapabilityTool | governed business | publication.publish | CapabilityInvocationService |
| PublishWorkflowTool | GovernedCapabilityTool | lifecycle | workflow.publish | CapabilityInvocationService |
| RecordMemoryTool | GovernedCapabilityTool | governed business | memory.record | CapabilityInvocationService |
| RequestApprovalTool | AuthorizedTool | governed business | approval.request | Operation direct |
| ResumeAgentExecutionTool | AgentExecutionResourceTool | lifecycle | - | Operation direct |
| ResumeWorkflowExecutionTool | GovernedCapabilityTool | lifecycle | workflow.resume | CapabilityInvocationService |
| RetrieveEnterpriseContextTool | GovernedCapabilityTool | governed business | enterprise.context.retrieve | CapabilityInvocationService |
| RetrieveKnowledgeTool | GovernedCapabilityTool | governed business | knowledge.retrieve | CapabilityInvocationService |
| RetrieveMemoryTool | GovernedCapabilityTool | governed business | memory.retrieve | CapabilityInvocationService |
| StartWorkflowTool | GovernedCapabilityTool | lifecycle | workflow.execute | CapabilityInvocationService |
| SubmitContentForReviewTool | GovernedCapabilityTool | governed business | marketing.content.review | CapabilityInvocationService |
| TransitionAgentAssignmentTool | AgentAssignmentResourceTool | lifecycle | agent.assignment.transition | Operation direct |
| TransitionCampaignTool | DomainTransitionTool | governed business | marketing.campaign.lifecycle | Operation direct |
| TransitionContentSeriesTool | DomainTransitionTool | governed business | marketing.content-series.lifecycle | Operation direct |
| UpdateAgentAssignmentTool | AgentAssignmentResourceTool | lifecycle | agent.assignment.update | Operation direct |
| UpdateAudienceTool | DomainMutationTool | governed business | marketing.audience.update | Operation direct |
| UpdateCampaignTool | DomainMutationTool | governed business | marketing.campaign.update | Operation direct |
| UpdateChannelTool | DomainMutationTool | governed business | marketing.channel.update | Operation direct |
| UpdateContentItemTool | GovernedCapabilityTool | governed business | marketing.content.update | CapabilityInvocationService |
| UpdateContentSeriesTool | DomainMutationTool | governed business | marketing.content-series.update | Operation direct |
| UpdateKnowledgeIndexTool | KnowledgeResourceTool | invalid/incomplete mapping | - | Operation direct |
| UpdateKnowledgeUnitTool | KnowledgeResourceTool | invalid/incomplete mapping | - | Operation direct |
| UpdateMemoryTool | MemoryResourceTool | invalid/incomplete mapping | - | Operation direct |
| UpdateObjectiveTool | DomainMutationTool | governed business | marketing.objective.update | Operation direct |
| UpdateProjectTool | DomainMutationTool | governed business | marketing.project.update | Operation direct |
| UpdateSocialAccountTool | DomainMutationTool | governed business | marketing.social-account.update | Operation direct |
| UpdateStrategyTool | GovernedCapabilityTool | governed business | strategy.update | CapabilityInvocationService |
| UpdateWorkItemTool | GovernedCapabilityTool | governed business | work.item.update | CapabilityInvocationService |
| VerifyMarketingGraphTool | GovernedCapabilityTool | governed business | marketing.graph.verify | CapabilityInvocationService |


## Framework/base classes excluded from the exposed-tool inventory

- AgentAssignmentResourceTool
- AgentExecutionResourceTool
- AuthorizedTool
- DomainMutationTool
- DomainTransitionTool
- GovernedCapabilityTool
- KnowledgeResourceTool
- MemoryResourceTool


## Unmapped business mutation Tools

| Tool | Current Operation/resource path | Required follow-up |
|---|---|---|
| ArchiveKnowledgeUnitTool | ArchiveKnowledgeUnit | Add an explicit Capability/Operation mapping and canonical Capability execution. |
| ArchiveMemoryTool | ArchiveMemory | Add an explicit Capability/Operation mapping and canonical Capability execution. |
| CreateMemoryTool | CreateMemory | Add an explicit Capability/Operation mapping and canonical Capability execution. |
| UpdateKnowledgeIndexTool | UpdateKnowledgeIndex | Add an explicit Capability/Operation mapping and canonical Capability execution. |
| UpdateKnowledgeUnitTool | UpdateKnowledgeUnit | Add an explicit Capability/Operation mapping and canonical Capability execution. |
| UpdateMemoryTool | UpdateMemory | Add an explicit Capability/Operation mapping and canonical Capability execution. |


## Operation inventory

| Operation | Capability | MCP Tool | Classification |
|---|---|---|---|
| AnalyzeBusinessContext | business.analysis | analyze-business-context | governed |
| ApprovalRequestCreate | approval.request | mcp_agent_approval_request | governed |
| ArchiveKnowledgeUnit | - | - | resource/lifecycle or follow-up candidate |
| ArchiveMemory | - | - | resource/lifecycle or follow-up candidate |
| CampaignLifecycle | marketing.campaign.lifecycle | transition-campaign | governed |
| CancelAgentExecution | - | - | resource/lifecycle or follow-up candidate |
| ContentSeriesLifecycle | marketing.content-series.lifecycle | transition-content-series | governed |
| ContinueAgentExecution | agent.continue | mcp_agent_continue | lifecycle |
| CreateAgentAssignment | agent.assignment.create | mcp_agent_assignment_create | lifecycle |
| CreateAgentExecution | - | - | resource/lifecycle or follow-up candidate |
| CreateContentItem | marketing.content.create | create-content-item | governed |
| CreateKnowledgeIndex | knowledge.index.create | create-knowledge-index | governed |
| CreateKnowledgeItem | knowledge.item.create | create-knowledge-item | governed |
| CreateKnowledgeUnit | knowledge.unit.create | create-knowledge-unit | governed |
| CreateMarketingStrategy | marketing.strategy.create | create-marketing-strategy | governed |
| CreateMemory | - | - | resource/lifecycle or follow-up candidate |
| CreatePlannedAsset | marketing.asset.create | create-asset | governed |
| CreateScript | marketing.script.create | create-script | governed |
| CreateStrategy | strategy.create | create-strategy | governed |
| CreateWorkItem | work.item.create | create-work-item | governed |
| CreateWorkflow | workflow.create | mcp_workflow_create | lifecycle |
| UpdateWorkflow | workflow.update | mcp_workflow_update | lifecycle |
| DefineMarketingStrategySection | marketing.strategy.section.define | define-marketing-strategy-section | governed |
| DelegateAgent | agent.delegate | mcp_agent_delegate | lifecycle |
| DiscoverWorkflows | workflow.discover | list-workflows | governed |
| DomainMutationToolOperation | - | - | framework adapter |
| DomainTransitionToolOperation | - | - | framework adapter |
| EnterpriseContextCreate | enterprise.context.create | create-enterprise-context | governed |
| EnterpriseContextRetrieve | enterprise.context.retrieve | retrieve-enterprise-context | governed |
| EnterpriseCreate | enterprise.create | create-enterprise | governed |
| ExecuteAgent | agent.execute | mcp_agent_execute | lifecycle |
| GenerateFinancialReport | finance.report.generate | generate-financial-report | governed |
| GetAgentAssignment | - | - | internal/read operation |
| GetAgentDelegation | - | - | internal/read operation |
| GetAgentExecution | - | - | internal/read operation |
| GetKnowledgeIndex | - | - | internal/read operation |
| GetWorkflow | workflow.get | workflow-get | read/query |
| GetWorkflowTool | workflow.get | workflow-get | read/query MCP Tool |
| GetKnowledgeUnit | - | - | internal/read operation |
| GetMemory | - | - | internal/read operation |
| InspectWorkflowExecution | workflow.inspect | mcp_workflow_inspect | lifecycle |
| ListAgentAssignments | - | - | internal/read operation |
| ListAgentDelegations | - | - | internal/read operation |
| ListAgentExecutions | - | - | internal/read operation |
| ListKnowledgeIndexes | - | - | internal/read operation |
| ListKnowledgeUnits | - | - | internal/read operation |
| ListMemory | - | - | internal/read operation |
| MarkContentPublicationReady | marketing.content.publication-ready | mark-content-publication-ready | governed |
| MarketingAudienceArchive | marketing.audience.archive | archive-audience | governed |
| MarketingAudienceCreate | marketing.audience.create | create-audience | governed |
| MarketingAudienceUpdate | marketing.audience.update | update-audience | governed |
| MarketingCampaignCreate | marketing.campaign.create | create-campaign | governed |
| MarketingCampaignUpdate | marketing.campaign.update | update-campaign | governed |
| MarketingChannelArchive | marketing.channel.archive | archive-channel | governed |
| MarketingChannelCreate | marketing.channel.create | create-channel | governed |
| MarketingChannelUpdate | marketing.channel.update | update-channel | governed |
| MarketingContentSeriesCreate | marketing.content-series.create | create-content-series | governed |
| MarketingContentSeriesUpdate | marketing.content-series.update | update-content-series | governed |
| MarketingStrategyArchive | marketing.strategy.archive | archive-marketing-strategy | governed |
| ObjectiveCreate | marketing.objective.create | create-objective | governed |
| ObjectiveUpdate | marketing.objective.update | update-objective | governed |
| PlanMarketing | marketing.plan | plan-marketing | governed |
| ProjectCreate | marketing.project.create | create-project | governed |
| ProjectUpdate | marketing.project.update | update-project | governed |
| PublishContent | publication.publish | publish-content | governed |
| PublishWorkflow | workflow.publish | mcp_workflow_publish | lifecycle |
| RecordMemory | memory.record | record-memory | governed |
| ResumeAgentExecution | - | - | resource/lifecycle or follow-up candidate |
| ResumeWorkflowExecution | workflow.resume | mcp_workflow_resume | lifecycle |
| RetrieveKnowledge | knowledge.retrieve | retrieve-knowledge | governed |
| RetrieveMemory | memory.retrieve | retrieve-memory | governed |
| IntegrationConnectionCreate | integration.connection.create | create-connection | governed |
| SocialAccountConnect | marketing.social-account.connect | connect-social-account | governed |
| SocialAccountDisconnect | marketing.social-account.disconnect | disconnect-social-account | governed |
| SocialAccountUpdate | marketing.social-account.update | update-social-account | governed |
| StartWorkflow | workflow.execute | mcp_workflow_execute | lifecycle |
| SubmitContentForReview | marketing.content.review | submit-content-for-review | governed |
| TransitionAgentAssignment | agent.assignment.transition | mcp_agent_assignment_transition | lifecycle |
| UpdateAgentAssignment | agent.assignment.update | mcp_agent_assignment_update | lifecycle |
| UpdateContentItem | marketing.content.update | update-content-item | governed |
| UpdateKnowledgeIndex | - | - | resource/lifecycle or follow-up candidate |
| UpdateKnowledgeUnit | - | - | resource/lifecycle or follow-up candidate |
| UpdateMemory | - | - | resource/lifecycle or follow-up candidate |
| UpdateStrategy | strategy.update | update-strategy | governed |
| UpdateWorkItem | work.item.update | update-work-item | governed |
| VerifyMarketingGraph | marketing.graph.verify | verify-marketing-graph | governed |


## Findings and follow-up dependencies

### 1. Lifecycle classification
Agent execution, continuation, cancellation, delegation, and assignment lifecycle Tools are currently mixed into the Capability registry. Workflow create, publish, start, resume and execution inspection are also represented there. Issue #375 must separate these and use mcp_agent_* / mcp_workflow_* naming.

### 2. Canonical boundary bypasses
DomainMutationTool, DomainTransitionTool, CreateEnterpriseTool, RecordMemoryTool, RetrieveMemoryTool and RequestApprovalTool resolve registry mappings but execute the mapped Operation directly. This is a duplicate execution path relative to CapabilityInvocationService and is a remediation dependency for #374 and the Agent/Workflow convergence work.

### 3. Missing business mappings
CreateMemoryTool, UpdateMemoryTool, ArchiveMemoryTool, ArchiveKnowledgeUnitTool, UpdateKnowledgeIndexTool and UpdateKnowledgeUnitTool perform business work without an explicit CapabilityRegistry mapping. These are follow-up remediation items and must not remain implicit execution paths.

### 4. Read/query surface
Get*, List* and discovery Tools are documented as read/query Tools rather than mutation Capabilities. They are not silently promoted into the business 1:1 contract.

### 5. Existing executable checks
CapabilityRegistryTest already verifies uniqueness and explicit Operation/Tool contracts for the registered registry. McpSurfaceContractTest verifies that concrete Tool classes are registered with Cr8orServer. This issue does not create a second runtime registry.

## Dependency order
1. #373: audit baseline.
2. #375: lifecycle separation and naming.
3. #374: enforce business Tool -> Capability -> Operation and canonical invocation.
4. #376: Agent convergence.
5. #377: Workflow convergence.

## Constraints verified
- Filament is not included in the MCP business contract.
- External-result integration webhooks are not treated as command Tools.
- No hardcoded workflow definition was introduced.
- No model provider is required for this audit.