# MCP authorization and tenancy matrix

This matrix covers every concrete public tool registered from `app/Mcp/Tools` and complements the canonical name/class inventory in [`mcp.md`](mcp.md). It is intentionally tied to concrete classes; `McpAuthorizationMatrixTest` fails if a tool is added, removed, renamed, or assigned to a different authorization family without updating this matrix.

## Authorization contract

Every tool is authenticated through `AuthorizedTool::shouldRegister` and rejects a missing `User` in its handler. That registration guard only establishes that the user has at least one membership; it is **not** resource authorization. The concrete boundary below remains mandatory.

| Boundary family | Enforcement contract |
|---|---|
| `GovernedCapabilityTool` | `CapabilityRegistry` maps tool → Capability → Operation. `CapabilityInvocationService` checks trusted actor/enterprise context, Agent/Expert or workflow-stage provenance, and approval requirements; the Operation/domain service must enforce target-specific policy and invariants. |
| `DomainMutationTool` | Registry maps tool → Capability → Operation. The tool authorizes the declared human ability; `DomainResourceService` rechecks target/enterprise policy and ownership before mutation. |
| `DomainTransitionTool` | Registry maps tool → Capability → Operation. The target update policy and domain lifecycle service enforce ownership and legal state transitions. |
| `DiscoveryGetTool` | Query is scoped to the actor’s organization membership before loading a record; `Gate::authorize(view, record)` is applied after the scoped lookup. |
| `DiscoveryListTool` | Read-only query uses the concrete tool’s organization/enterprise scope and filter rules. No mutation occurs. A list scope is the access boundary, so each concrete `scopeQuery` override is part of the audit contract. |
| `AgentAssignmentResourceTool` | Delegates to its concrete Operation; `AgentAssignmentService` checks organization membership, enterprise ownership, and action-specific policy. |
| `AgentExecutionResourceTool` | Delegates to its concrete Operation; execution/resource services verify actor/enterprise/assignment binding and policy before exposing or changing runtime state. |
| `KnowledgeResourceTool` / `MemoryResourceTool` | Delegates to its concrete Operation. Where registered in `CapabilityRegistry`, it uses the governed invocation boundary; otherwise its domain service must enforce enterprise/owner scope. |
| Explicit direct-tool boundary | The only direct exceptions are `CreateEnterpriseTool` (organization creation policy), `RequestApprovalTool` (Operation-level MCP capability authorization plus approval domain services), and `GetCapabilityTool` / `ListCapabilitiesTool` (read-only platform capability catalogue). |

## Concrete public tool mapping

`Capability` and `Operation` are shown where the registry or resource tool declares them. Read-only discovery tools intentionally execute a scoped query in the shared query boundary rather than a state-changing business Operation; their concrete model and scope implementation are recorded below. `—` means no separate CapabilityRegistry entry.

| Public tool | Tool class | Boundary family | Capability | Operation / query target | Authorization / tenant boundary |
|---|---|---|---|---|---|
| analyze-business-context | `AnalyzeBusinessContextTool` | `GovernedCapabilityTool` | `business.analysis` | `AnalyzeBusinessContext` | CapabilityInvocationService + operation/domain policy |
| approve-content-item | `ApproveContentItemTool` | `GovernedCapabilityTool` | `marketing.content.approve` | `ApproveContentItem` | CapabilityInvocationService + workflow approval match + ContentItem policy/lifecycle |
| archive-audience | `ArchiveAudienceTool` | `DomainMutationTool` | `marketing.audience.archive` | `MarketingAudienceArchive` | human target policy + DomainResourceService policy |
| archive-channel | `ArchiveChannelTool` | `DomainMutationTool` | `marketing.channel.archive` | `MarketingChannelArchive` | human target policy + DomainResourceService policy |
| archive-knowledge-unit | `ArchiveKnowledgeUnitTool` | `KnowledgeResourceTool` | `knowledge.unit.archive` | `ArchiveKnowledgeUnit` | Operation/domain service; governed when registry-mapped |
| archive-marketing-strategy | `ArchiveMarketingStrategyTool` | `DomainMutationTool` | `marketing.strategy.archive` | `MarketingStrategyArchive` | human target policy + DomainResourceService policy |
| archive-memory | `ArchiveMemoryTool` | `MemoryResourceTool` | `memory.archive` | `ArchiveMemory` | Operation/domain service; governed when registry-mapped |
| cancel-agent-execution | `CancelAgentExecutionTool` | `AgentExecutionResourceTool` | — | `CancelAgentExecution` | AgentExecutionResourceService / runtime scope + policy |
| continue-agent-execution | `ContinueAgentExecutionTool` | `AgentExecutionResourceTool` | `agent.continue` | `ContinueAgentExecution` | AgentExecutionResourceService / runtime scope + policy |
| create-agent-assignment | `CreateAgentAssignmentTool` | `AgentAssignmentResourceTool` | `agent.assignment.create` | `CreateAgentAssignment` | AgentAssignmentService scope + policy |
| create-agent-execution | `CreateAgentExecutionTool` | `AgentExecutionResourceTool` | — | `CreateAgentExecution` | AgentExecutionResourceService / runtime scope + policy |
| create-asset | `CreateAssetTool` | `DomainMutationTool` | `marketing.asset.create` | `CreatePlannedAsset` | human target policy + DomainResourceService policy |
| create-audience | `CreateAudienceTool` | `DomainMutationTool` | `marketing.audience.create` | `MarketingAudienceCreate` | human target policy + DomainResourceService policy |
| create-campaign | `CreateCampaignTool` | `DomainMutationTool` | `marketing.campaign.create` | `MarketingCampaignCreate` | human target policy + DomainResourceService policy |
| create-channel | `CreateChannelTool` | `DomainMutationTool` | `marketing.channel.create` | `MarketingChannelCreate` | human target policy + DomainResourceService policy |
| create-competitor | `CreateCompetitorTool` | `GovernedCapabilityTool` | `marketing.competitor.create_and_report` | `CreateCompetitorAndGenerateReport` | McpCapabilityAuthorizer + CompetitorPolicy::createForEnterprise + Enterprise view policy + atomic competitor/report transaction |
| create-competitor-record | `CreateCompetitorRecordTool` | `GovernedCapabilityTool` | `marketing.competitor.create` | `CreateCompetitor` | CapabilityInvocationService + `CompetitorPolicy::createForEnterprise` + StrategicContextService |
| create-content-item | `CreateContentItemTool` | `GovernedCapabilityTool` | `marketing.content.create` | `CreateContentItem` | CapabilityInvocationService + operation/domain policy |
| create-content-series | `CreateContentSeriesTool` | `DomainMutationTool` | `marketing.content-series.create` | `MarketingContentSeriesCreate` | human target policy + DomainResourceService policy |
| create-enterprise | `CreateEnterpriseTool` | `Explicit direct-tool boundary` | `enterprise.create` | `EnterpriseCreate` | organization scope + EnterprisePolicy::createForOrganization |
| create-enterprise-context | `CreateEnterpriseContextTool` | `DomainMutationTool` | `enterprise.context.create` | `EnterpriseContextCreate` | human target policy + DomainResourceService policy |
| create-knowledge-index | `CreateKnowledgeIndexTool` | `GovernedCapabilityTool` | `knowledge.index.create` | `CreateKnowledgeIndex` | CapabilityInvocationService + operation/domain policy |
| create-knowledge-item | `CreateKnowledgeItemTool` | `GovernedCapabilityTool` | `knowledge.item.create` | `CreateKnowledgeItem` | CapabilityInvocationService + operation/domain policy |
| create-knowledge-unit | `CreateKnowledgeUnitTool` | `GovernedCapabilityTool` | `knowledge.unit.create` | `CreateKnowledgeUnit` | CapabilityInvocationService + operation/domain policy |
| create-marketing-strategy | `CreateMarketingStrategyTool` | `GovernedCapabilityTool` | `marketing.strategy.create` | `CreateMarketingStrategy` | CapabilityInvocationService + operation/domain policy |
| create-memory | `CreateMemoryTool` | `MemoryResourceTool` | `memory.create` | `CreateMemory` | Operation/domain service; governed when registry-mapped |
| create-objective | `CreateObjectiveTool` | `DomainMutationTool` | `marketing.objective.create` | `ObjectiveCreate` | human target policy + DomainResourceService policy |
| create-project | `CreateProjectTool` | `DomainMutationTool` | `marketing.project.create` | `ProjectCreate` | human target policy + DomainResourceService policy |
| create-script | `CreateScriptTool` | `DomainMutationTool` | `marketing.script.create` | `CreateScript` | human target policy + DomainResourceService policy |
| create-strategy | `CreateStrategyTool` | `GovernedCapabilityTool` | `strategy.create` | `CreateStrategy` | CapabilityInvocationService + operation/domain policy |
| create-task | `CreateTaskTool` | `GovernedCapabilityTool` | `work.task.create` | `CreateTask` | CapabilityInvocationService + operation/domain policy |
| create-workflow | `CreateWorkflowTool` | `GovernedCapabilityTool` | `workflow.create` | `CreateWorkflow` | CapabilityInvocationService + operation/domain policy |
| define-marketing-strategy-section | `DefineMarketingStrategySectionTool` | `GovernedCapabilityTool` | `marketing.strategy.section.define` | `DefineMarketingStrategySection` | CapabilityInvocationService + operation/domain policy |
| delegate-agent | `DelegateAgentTool` | `GovernedCapabilityTool` | `agent.delegate` | `DelegateAgent` | CapabilityInvocationService + operation/domain policy |
| disable-social-account | `DisableSocialAccountTool` | `GovernedCapabilityTool` | `marketing.social-account.disable` | `SocialAccountDisable` | CapabilityInvocationService + `SocialAccountPolicy::disable` + DomainResourceService; approval required for Agent execution |
| duplicate-workflow | `DuplicateWorkflowTool` | `GovernedCapabilityTool` | `workflow.duplicate` | `DuplicateWorkflow` | CapabilityInvocationService + operation/domain policy |
| execute-agent | `ExecuteAgentTool` | `GovernedCapabilityTool` | `agent.execute` | `ExecuteAgent` | CapabilityInvocationService + operation/domain policy |
| generate-competitive-analysis-report | `GenerateCompetitiveAnalysisReportTool` | `GovernedCapabilityTool` | marketing.competitor.report.generate | GenerateCompetitiveAnalysisReport | McpCapabilityAuthorizer + Enterprise view policy + CompetitiveAnalysisReportingService Enterprise scoping |
| generate-financial-report | `GenerateFinancialReportTool` | `GovernedCapabilityTool` | `finance.report.generate` | `GenerateFinancialReport` | CapabilityInvocationService + operation/domain policy |
| get-agent | `GetAgentDescriptorTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\AgentDescriptor` | organization-scoped lookup + `Gate::view` |
| get-agent-assignment | `GetAgentAssignmentTool` | `AgentAssignmentResourceTool` | — | `GetAgentAssignment` | AgentAssignmentService scope + policy |
| get-agent-delegation | `GetAgentDelegationTool` | `AgentAssignmentResourceTool` | — | `GetAgentDelegation` | AgentAssignmentService scope + policy |
| get-agent-execution | `GetExecutionTool` | `AgentExecutionResourceTool` | — | `GetAgentExecution` | AgentExecutionResourceService / runtime scope + policy |
| get-approval-request | `GetApprovalRequestTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\ApprovalRequest` | organization-scoped lookup + `Gate::view` |
| get-audience | `GetAudienceTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Audience` | organization-scoped lookup + `Gate::view` |
| get-campaign | `GetCampaignTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Campaign` | organization-scoped lookup + `Gate::view` |
| get-capability | `GetCapabilityTool` | `Explicit direct-tool boundary` | — | explicit handler (see family contract) | authenticated member + read-only platform catalogue |
| get-channel | `GetChannelTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Channel` | organization-scoped lookup + `Gate::view` |
| get-content-item | `GetContentItemTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\ContentItem` | organization-scoped lookup + `Gate::view` |
| get-content-series | `GetContentSeriesTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\ContentSeries` | organization-scoped lookup + `Gate::view` |
| get-enterprise | `GetEnterpriseTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Enterprise` | organization-scoped lookup + `Gate::view` |
| get-expert-descriptor | `GetExpertDescriptorTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\ExpertDescriptor` | organization-scoped lookup + `Gate::view` |
| get-goal | `GetGoalTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Goal` | organization-scoped lookup + `Gate::view` |
| get-initiative | `GetInitiativeTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Initiative` | organization-scoped lookup + `Gate::view` |
| get-knowledge-index | `GetKnowledgeIndexTool` | `KnowledgeResourceTool` | — | `GetKnowledgeIndex` | Operation/domain service; governed when registry-mapped |
| get-knowledge-unit | `GetKnowledgeUnitTool` | `KnowledgeResourceTool` | — | `GetKnowledgeUnit` | Operation/domain service; governed when registry-mapped |
| get-kpi | `GetKpiTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Kpi` | organization-scoped lookup + `Gate::view` |
| get-marketing-strategy | `GetMarketingStrategyTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\MarketingStrategy` | organization-scoped lookup + `Gate::view` |
| get-memory | `GetMemoryTool` | `MemoryResourceTool` | — | `GetMemory` | Operation/domain service; governed when registry-mapped |
| get-objective | `GetObjectiveTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Objective` | organization-scoped lookup + `Gate::view` |
| get-plan | `GetPlanTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Plan` | organization-scoped lookup + `Gate::view` |
| get-project | `GetProjectTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Project` | organization-scoped lookup + `Gate::view` |
| get-publication | `GetPublicationTool` | `DiscoveryGetTool` | — | scoped query: `App\\Models\\Publication` | enterprise membership query + `Gate::view`; latest provider result excludes payload |
| get-social-account | `GetSocialAccountTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\SocialAccount` | organization-scoped lookup + `Gate::view` |
| get-strategy | `GetStrategyTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Strategy` | organization-scoped lookup + `Gate::view` |
| get-task | `GetTaskTool` | `DiscoveryGetTool` | — | scoped query: `App\Models\Task` | organization-scoped lookup + `Gate::view` |
| get-workflow | `GetWorkflowTool` | `GovernedCapabilityTool` | `workflow.get` | `GetWorkflow` | CapabilityInvocationService + operation/domain policy |
| get-workflow-execution | `GetWorkflowExecutionTool` | `GovernedCapabilityTool` | `workflow.inspect` | `InspectWorkflowExecution` | CapabilityInvocationService + operation/domain policy |
| list-agent-assignments | `ListAgentAssignmentsTool` | `AgentAssignmentResourceTool` | — | `ListAgentAssignments` | AgentAssignmentService scope + policy |
| list-agent-delegations | `ListAgentDelegationsTool` | `AgentAssignmentResourceTool` | — | `ListAgentDelegations` | AgentAssignmentService scope + policy |
| list-agent-executions | `ListExecutionTool` | `AgentExecutionResourceTool` | — | `ListAgentExecutions` | AgentExecutionResourceService / runtime scope + policy |
| list-agents | `ListAgentDescriptorTool` | `DiscoveryListTool` | — | scoped query: `App\Models\AgentDescriptor` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-approval-requests | `ListApprovalRequestTool` | `DiscoveryListTool` | — | scoped query: `App\Models\ApprovalRequest` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-audiences | `ListAudienceTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Audience` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-campaigns | `ListCampaignTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Campaign` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-capabilities | `ListCapabilitiesTool` | `Explicit direct-tool boundary` | — | explicit handler (see family contract) | authenticated member + read-only platform catalogue |
| list-channels | `ListChannelTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Channel` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-content-items | `ListContentItemTool` | `DiscoveryListTool` | — | scoped query: `App\Models\ContentItem` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-content-series | `ListContentSeriesTool` | `DiscoveryListTool` | — | scoped query: `App\Models\ContentSeries` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-enterprises | `ListEnterpriseTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Enterprise` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-experts | `ListExpertDescriptorTool` | `DiscoveryListTool` | — | scoped query: `App\Models\ExpertDescriptor` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-goals | `ListGoalTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Goal` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-initiatives | `ListInitiativeTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Initiative` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-knowledge-indexes | `ListKnowledgeIndexesTool` | `KnowledgeResourceTool` | — | `ListKnowledgeIndexes` | Operation/domain service; governed when registry-mapped |
| list-knowledge-units | `ListKnowledgeUnitsTool` | `KnowledgeResourceTool` | — | `ListKnowledgeUnits` | Operation/domain service; governed when registry-mapped |
| list-kpis | `ListKpiTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Kpi` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-marketing-strategies | `ListMarketingStrategyTool` | `DiscoveryListTool` | — | scoped query: `App\Models\MarketingStrategy` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-memories | `ListMemoryTool` | `MemoryResourceTool` | — | `ListMemory` | Operation/domain service; governed when registry-mapped |
| list-objectives | `ListObjectiveTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Objective` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-plans | `ListPlanTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Plan` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-projects | `ListProjectTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Project` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-publication-results | `ListPublicationResultsTool` | `DiscoveryListTool` | — | scoped query: `App\\Models\\PublicationResult` | enterprise membership query + `Gate::viewAny`; provider payload excluded |
| list-publications | `ListPublicationTool` | `DiscoveryListTool` | — | scoped query: `App\\Models\\Publication` | enterprise membership query + `Gate::viewAny` |
| list-social-accounts | `ListSocialAccountTool` | `DiscoveryListTool` | — | scoped query: `App\Models\SocialAccount` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-strategies | `ListStrategyTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Strategy` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-tasks | `ListTaskTool` | `DiscoveryListTool` | — | scoped query: `App\Models\Task` | concrete `scopeQuery` + optional enterprise/filter scope |
| list-workflows | `ListWorkflowsTool` | `GovernedCapabilityTool` | `workflow.discover` | `DiscoverWorkflows` | CapabilityInvocationService + operation/domain policy |
| mark-content-publication-ready | `MarkContentPublicationReadyTool` | `GovernedCapabilityTool` | `marketing.content.publication-ready` | `MarkContentPublicationReady` | CapabilityInvocationService + operation/domain policy |
| plan-marketing | `PlanMarketingTool` | `GovernedCapabilityTool` | `marketing.plan` | `PlanMarketing` | CapabilityInvocationService + operation/domain policy |
| publish-content | `PublishContentTool` | `GovernedCapabilityTool` | `publication.publish` | `PublishContent` | CapabilityInvocationService + operation/domain policy |
| publish-workflow | `PublishWorkflowTool` | `GovernedCapabilityTool` | `workflow.publish` | `PublishWorkflow` | CapabilityInvocationService + operation/domain policy |
| reconcile-publication-status | `ReconcilePublicationStatusTool` | `GovernedCapabilityTool` | `publication.status.reconcile` | `ReconcilePublicationStatus` | CapabilityInvocationService + `PublicationPolicy::reconcileStatus` + PublishingService; no provider write and no approval required |
| record-memory | `RecordMemoryTool` | `GovernedCapabilityTool` | `memory.record` | `RecordMemory` | CapabilityInvocationService + operation/domain policy |
| request-approval | `RequestApprovalTool` | `Explicit direct-tool boundary` | `approval.request` | `ApprovalRequestCreate` | McpCapabilityAuthorizer + ApprovalRequestService + ApprovalPolicyService |
| resume-agent-execution | `ResumeAgentExecutionTool` | `AgentExecutionResourceTool` | — | `ResumeAgentExecution` | AgentExecutionResourceService / runtime scope + policy |
| resume-workflow-execution | `ResumeWorkflowExecutionTool` | `GovernedCapabilityTool` | `workflow.resume` | `ResumeWorkflowExecution` | CapabilityInvocationService + operation/domain policy |
| retrieve-enterprise-context | `RetrieveEnterpriseContextTool` | `GovernedCapabilityTool` | `enterprise.context.retrieve` | `EnterpriseContextRetrieve` | CapabilityInvocationService + operation/domain policy |
| retrieve-knowledge | `RetrieveKnowledgeTool` | `GovernedCapabilityTool` | `knowledge.retrieve` | `RetrieveKnowledge` | CapabilityInvocationService + operation/domain policy |
| retrieve-memory | `RetrieveMemoryTool` | `GovernedCapabilityTool` | `memory.retrieve` | `RetrieveMemory` | CapabilityInvocationService + operation/domain policy |
| start-workflow-execution | `StartWorkflowTool` | `GovernedCapabilityTool` | `workflow.execute` | `StartWorkflow` | CapabilityInvocationService + operation/domain policy |
| submit-content-for-review | `SubmitContentForReviewTool` | `GovernedCapabilityTool` | `marketing.content.review` | `SubmitContentForReview` | CapabilityInvocationService + operation/domain policy |
| transition-agent-assignment | `TransitionAgentAssignmentTool` | `AgentAssignmentResourceTool` | `agent.assignment.transition` | `TransitionAgentAssignment` | AgentAssignmentService scope + policy |
| transition-campaign | `TransitionCampaignTool` | `DomainTransitionTool` | `marketing.campaign.lifecycle` | `CampaignLifecycle` | target policy + lifecycle service |
| transition-content-series | `TransitionContentSeriesTool` | `DomainTransitionTool` | `marketing.content-series.lifecycle` | `ContentSeriesLifecycle` | target policy + lifecycle service |
| update-agent-assignment | `UpdateAgentAssignmentTool` | `AgentAssignmentResourceTool` | `agent.assignment.update` | `UpdateAgentAssignment` | AgentAssignmentService scope + policy |
| update-audience | `UpdateAudienceTool` | `DomainMutationTool` | `marketing.audience.update` | `MarketingAudienceUpdate` | human target policy + DomainResourceService policy |
| update-campaign | `UpdateCampaignTool` | `DomainMutationTool` | `marketing.campaign.update` | `MarketingCampaignUpdate` | human target policy + DomainResourceService policy |
| update-channel | `UpdateChannelTool` | `DomainMutationTool` | `marketing.channel.update` | `MarketingChannelUpdate` | human target policy + DomainResourceService policy |
| update-content-item | `UpdateContentItemTool` | `GovernedCapabilityTool` | `marketing.content.update` | `UpdateContentItem` | CapabilityInvocationService + operation/domain policy |
| update-content-series | `UpdateContentSeriesTool` | `DomainMutationTool` | `marketing.content-series.update` | `MarketingContentSeriesUpdate` | human target policy + DomainResourceService policy |
| update-enterprise-context | `UpdateEnterpriseContextTool` | `DomainMutationTool` | `enterprise.context.update` | `EnterpriseContextUpdate` | human target policy + DomainResourceService policy |
| update-knowledge-index | `UpdateKnowledgeIndexTool` | `KnowledgeResourceTool` | `knowledge.index.update` | `UpdateKnowledgeIndex` | Operation/domain service; governed when registry-mapped |
| update-knowledge-unit | `UpdateKnowledgeUnitTool` | `KnowledgeResourceTool` | `knowledge.unit.update` | `UpdateKnowledgeUnit` | Operation/domain service; governed when registry-mapped |
| update-memory | `UpdateMemoryTool` | `MemoryResourceTool` | `memory.update` | `UpdateMemory` | Operation/domain service; governed when registry-mapped |
| update-objective | `UpdateObjectiveTool` | `DomainMutationTool` | `marketing.objective.update` | `ObjectiveUpdate` | human target policy + DomainResourceService policy |
| update-project | `UpdateProjectTool` | `DomainMutationTool` | `marketing.project.update` | `ProjectUpdate` | human target policy + DomainResourceService policy |
| update-social-account | `UpdateSocialAccountTool` | `GovernedCapabilityTool` | `marketing.social-account.update` | `SocialAccountUpdate` | CapabilityInvocationService + `SocialAccountPolicy::update` + DomainResourceService; approval required for Agent execution |
| update-strategy | `UpdateStrategyTool` | `GovernedCapabilityTool` | `strategy.update` | `UpdateStrategy` | CapabilityInvocationService + operation/domain policy |
| update-task | `UpdateTaskTool` | `GovernedCapabilityTool` | `work.task.update` | `UpdateTask` | CapabilityInvocationService + operation/domain policy |
| update-workflow | `UpdateWorkflowTool` | `GovernedCapabilityTool` | `workflow.update` | `UpdateWorkflow` | CapabilityInvocationService + operation/domain policy |
| verify-marketing-graph | `VerifyMarketingGraphTool` | `GovernedCapabilityTool` | `marketing.graph.verify` | `VerifyMarketingGraph` | CapabilityInvocationService + operation/domain policy |

Social authorization is intentionally a user-consented web lifecycle: OAuth initiation, callback validation, provider account discovery/attachment, refresh and revocation are handled by `SocialAuthorizationService`, not by generic MCP connection/account CRUD. The MCP `disable-social-account` action disables only the CR8OR destination. Publication list/get/result tools are enterprise-scoped and omit provider payloads and credential references.

## Cross-cutting invariants

- The public server registration test verifies every concrete MCP Tool is registered.
- The authorization surface contract verifies every public Tool inherits `AuthorizedTool` and belongs to one of the known enforcement families, with explicit exceptions kept small and enumerated.
- Agent-backed capability authorization rechecks current access to the assignment and validates execution-to-assignment, execution-to-tenant, and execution-to-actor binding. Historical execution ownership does not preserve access after membership is revoked.
- Agent/Expert permission does not replace the human actor’s current organization/enterprise authorization.
- Approval authorization is separate from capability exposure: required approvals must match capability, actor, assignment, execution, target context, and active policy before consumption.
- Resource-specific negative tests cover cross-organization/enterprise access, unauthorized roles, invalid lifecycle transitions, approval provenance, and execution binding. See the feature tests under `tests/Feature/Authorization` and `tests/Feature/Mcp`.
- Query tools are read-only and therefore have no mutation side effects; their `scopeQuery` implementation is security-sensitive and must be reviewed alongside the concrete model and policy when adding a tool.
