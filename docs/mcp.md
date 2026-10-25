# CR8OR MCP Architecture

MCP is the controlled AI-facing interface to CR8OR capabilities. It exposes authorized context and operations without becoming a duplicate application or domain layer.

## Canonical Capability → Operation → Tool naming

CR8OR deliberately separates the business authority, executable operation and MCP interface:

| Concept | Naming convention | Example |
|---|---|---|
| Capability | hierarchical dot notation | `marketing.content.create` |
| Operation | PascalCase | `CreateContentItem` |
| Tool | kebab-case | `create-content-item` |

A Tool does not grant authority. A Capability declaration does not grant permission. Authorization is evaluated server-side before the Operation executes.

The full per-tool authorization and tenancy mapping is maintained in [`mcp-authorization.md`](mcp-authorization.md). It records the shared boundary family, capability/operation mapping where present, and the concrete tenant/policy enforcement path for every public tool.

The canonical structured persona schema, product-association rules, update semantics, privacy boundaries, and examples for `create-persona` / `update-persona` are documented in [`client-personas.md`](client-personas.md). These tools retain the existing `marketing.persona.create` / `marketing.persona.update` capability-operation path; persona fields do not create a parallel MCP business layer.

The complete Product field contract, controlled values, commercial invariants, backward-compatible defaults, and `create-product` response shape are documented in [`product-domain.md`](product-domain.md). The tool remains a thin entry point through `product.create` → `ProductCreate` → `DomainResourceService`; schema and capability contract changes must stay aligned.

The `create-competitor` tool atomically creates/revises a competitor and generates a competitive-analysis report; `create-competitor-record` preserves the record-only capability. Contracts, enterprise authorization, versioning behavior, report provenance, retry semantics, and deployment verification are documented in [`competitor-creation.md`](competitor-creation.md).

## Public Tool rename and compatibility decision

This change standardizes the current public identifiers in place. Repository-owned tests, examples, and runtime references are updated together. No legacy aliases are registered: aliases would keep obsolete identifiers publicly discoverable and create a second name for the same operation. External MCP clients and saved prompts/configuration are not fully observable from this repository, so consumers outside the repository must update to the canonical names below. This is a breaking public-interface change; business behavior, payloads, capability identifiers, and authorization remain unchanged.

| Previous identifier | Canonical identifier | Rationale |
| --- | --- | --- |
| `mcp_agent_execution_create` | `create-agent-execution` | Action first; explicit resource |
| `mcp_agent_continue` | `continue-agent-execution` | Names the execution being continued |
| `mcp_agent_assignment_create` | `create-agent-assignment` | Action first; explicit resource |
| `mcp_agent_execution_resume` | `resume-agent-execution` | Action first; explicit resource |
| `mcp_agent_execution_cancel` | `cancel-agent-execution` | Action first; explicit resource |
| `mcp_agent_execute` | `execute-agent` | Removes transport prefix; preserves behavior |
| `mcp_agent_assignment_update` | `update-agent-assignment` | Action first; explicit resource |
| `mcp_agent_assignment_transition` | `transition-agent-assignment` | Preserves lifecycle-specific action |
| `mcp_workflow_create` | `create-workflow` | Action first; removes transport prefix |
| `mcp_workflow_duplicate` | `duplicate-workflow` | Action first; removes transport prefix |
| `mcp_workflow_update` | `update-workflow` | Action first; removes transport prefix |
| `mcp_workflow_publish` | `publish-workflow` | Action first; removes transport prefix |
| `mcp_workflow_execute` | `start-workflow-execution` | Names the execution lifecycle action |
| `mcp_workflow_inspect` | `get-workflow-execution` | Aligns with the concrete retrieval behavior |
| `mcp_workflow_get` | `get-workflow` | Canonical single-resource read |
| `mcp_workflow_resume` | `resume-workflow-execution` | Names the execution lifecycle action |
| `mcp_agent_approval_request` | `request-approval` | Action first; concise domain verb |
| `mcp_agent_delegate` | `delegate-agent` | Action first; removes transport prefix |
| `workflow-get` | `get-workflow` | Consistent single-resource read |
| `get-execution` | `get-agent-execution` | Removes ambiguity about execution type |
| `list-executions` | `list-agent-executions` | Makes collection resource explicit |
| `get-expert` | `get-expert-descriptor` | Matches the actual resource |
| `list-memory` | `list-memories` | Uses plural collection naming |

## Verified public Tool inventory

The table below records the identifiers exposed by Laravel MCP, not connector-side wrapper names. Governed Tools map to one explicit Capability and Operation. Unregistered read/discovery/resource and lifecycle Tools are explicitly classified; registration does not grant authority. `McpSurfaceInventoryTest` compares the inventory section against every concrete public Tool class, requires exact set equality, rejects duplicate/stale/missing identifiers and class mappings, and checks registry/runtime agreement. Other sections may refer to the same Tool when explaining a domain-specific surface; those references are not inventory rows. `McpSurfaceContractTest` verifies server registration completeness.

| Public Tool identifier | Tool class | Capability | Operation |
| --- | --- | --- | --- |
| `analyze-business-context` | `AnalyzeBusinessContextTool` | `business.analysis` | `AnalyzeBusinessContext` |
| `archive-persona` | `ArchivePersonaTool` | `marketing.persona.archive` | `MarketingPersonaArchive` |
| `archive-channel` | `ArchiveChannelTool` | `marketing.channel.archive` | `MarketingChannelArchive` |
| `archive-knowledge-unit` | `ArchiveKnowledgeUnitTool` | `knowledge.unit.archive` | `ArchiveKnowledgeUnit` |
| `archive-marketing-strategy` | `ArchiveMarketingStrategyTool` | `marketing.strategy.archive` | `MarketingStrategyArchive` |
| `archive-memory` | `ArchiveMemoryTool` | `memory.archive` | `ArchiveMemory` |
| `cancel-agent-execution` | `CancelAgentExecutionTool` | Lifecycle (outside governed registry) | N/A |
| `continue-agent-execution` | `ContinueAgentExecutionTool` | `agent.continue` | `ContinueAgentExecution` |
| `create-agent-assignment` | `CreateAgentAssignmentTool` | `agent.assignment.create` | `CreateAgentAssignment` |
| `create-agent-execution` | `CreateAgentExecutionTool` | Lifecycle (outside governed registry) | N/A |
| `create-asset` | `CreateAssetTool` | `marketing.asset.create` | `CreatePlannedAsset` |
| `create-persona` | `CreatePersonaTool` | `marketing.persona.create` | `MarketingPersonaCreate` |
| `create-campaign` | `CreateCampaignTool` | `marketing.campaign.create` | `MarketingCampaignCreate` |
| `create-channel` | `CreateChannelTool` | `marketing.channel.create` | `MarketingChannelCreate` |
| `create-competitor` | `CreateCompetitorTool` | `marketing.competitor.create_and_report` | `CreateCompetitorAndGenerateReport` |
| `create-competitor-record` | `CreateCompetitorRecordTool` | `marketing.competitor.create` | `CreateCompetitor` |
| `approve-content-item` | `ApproveContentItemTool` | `marketing.content.approve` | `ApproveContentItem` |
| `create-content-item` | `CreateContentItemTool` | `marketing.content.create` | `CreateContentItem` |
| `create-content-series` | `CreateContentSeriesTool` | `marketing.content-series.create` | `MarketingContentSeriesCreate` |
| `create-enterprise` | `CreateEnterpriseTool` | `enterprise.create` | `EnterpriseCreate` |
| `create-enterprise-context` | `CreateEnterpriseContextTool` | `enterprise.context.create` | `EnterpriseContextCreate` |
| `create-knowledge-index` | `CreateKnowledgeIndexTool` | `knowledge.index.create` | `CreateKnowledgeIndex` |
| `create-knowledge-item` | `CreateKnowledgeItemTool` | `knowledge.item.create` | `CreateKnowledgeItem` |
| `create-knowledge-unit` | `CreateKnowledgeUnitTool` | `knowledge.unit.create` | `CreateKnowledgeUnit` |
| `create-marketing-strategy` | `CreateMarketingStrategyTool` | `marketing.strategy.create` | `CreateMarketingStrategy` |
| `create-memory` | `CreateMemoryTool` | `memory.create` | `CreateMemory` |
| `create-objective` | `CreateObjectiveTool` | `marketing.objective.create` | `ObjectiveCreate` |
| `create-project` | `CreateProjectTool` | `marketing.project.create` | `ProjectCreate` |
| `create-product` | `CreateProductTool` | `product.create` | `ProductCreate` |
| `update-product` | `UpdateProductTool` | `product.update` | `UpdateProduct` |
| `create-script` | `CreateScriptTool` | `marketing.script.create` | `CreateScript` |
| `create-strategy` | `CreateStrategyTool` | `strategy.create` | `CreateStrategy` |
| `create-task` | `CreateTaskTool` | `work.task.create` | `CreateTask` |
| `create-workflow` | `CreateWorkflowTool` | `workflow.create` | `CreateWorkflow` |
| `define-marketing-strategy-section` | `DefineMarketingStrategySectionTool` | `marketing.strategy.section.define` | `DefineMarketingStrategySection` |
| `delegate-agent` | `DelegateAgentTool` | `agent.delegate` | `DelegateAgent` |
| `disable-social-account` | `DisableSocialAccountTool` | `marketing.social-account.disable` | `SocialAccountDisable` |
| `duplicate-workflow` | `DuplicateWorkflowTool` | `workflow.duplicate` | `DuplicateWorkflow` |
| `execute-agent` | `ExecuteAgentTool` | `agent.execute` | `ExecuteAgent` |
| `generate-competitive-analysis-report` | `GenerateCompetitiveAnalysisReportTool` | `marketing.competitor.report.generate` | `GenerateCompetitiveAnalysisReport` |
| `generate-financial-report` | `GenerateFinancialReportTool` | `finance.report.generate` | `GenerateFinancialReport` |
| `get-agent` | `GetAgentDescriptorTool` | Unregistered read/discovery/resource | N/A |
| `get-agent-assignment` | `GetAgentAssignmentTool` | Unregistered read/discovery/resource | N/A |
| `get-agent-delegation` | `GetAgentDelegationTool` | Unregistered read/discovery/resource | N/A |
| `get-agent-execution` | `GetExecutionTool` | Unregistered read/discovery/resource | N/A |
| `get-approval-request` | `GetApprovalRequestTool` | Unregistered read/discovery/resource | N/A |
| `get-persona` | `GetPersonaTool` | Unregistered read/discovery/resource | N/A |
| `get-campaign` | `GetCampaignTool` | Unregistered read/discovery/resource | N/A |
| `get-capability` | `GetCapabilityTool` | Unregistered read/discovery/resource | N/A |
| `get-channel` | `GetChannelTool` | Unregistered read/discovery/resource | N/A |
| `get-content-item` | `GetContentItemTool` | Unregistered read/discovery/resource | N/A |
| `get-content-series` | `GetContentSeriesTool` | Unregistered read/discovery/resource | N/A |
| `get-enterprise` | `GetEnterpriseTool` | Unregistered read/discovery/resource | N/A |
| `get-expert-descriptor` | `GetExpertDescriptorTool` | Unregistered read/discovery/resource | N/A |
| `get-goal` | `GetGoalTool` | Unregistered read/discovery/resource | N/A |
| `get-initiative` | `GetInitiativeTool` | Unregistered read/discovery/resource | N/A |
| `get-knowledge-index` | `GetKnowledgeIndexTool` | Unregistered read/discovery/resource | N/A |
| `get-knowledge-unit` | `GetKnowledgeUnitTool` | Unregistered read/discovery/resource | N/A |
| `get-kpi` | `GetKpiTool` | Unregistered read/discovery/resource | N/A |
| `get-marketing-strategy` | `GetMarketingStrategyTool` | Unregistered read/discovery/resource | N/A |
| `get-memory` | `GetMemoryTool` | Unregistered read/discovery/resource | N/A |
| `get-objective` | `GetObjectiveTool` | Unregistered read/discovery/resource | N/A |
| `get-plan` | `GetPlanTool` | Unregistered read/discovery/resource | N/A |
| `get-project` | `GetProjectTool` | Unregistered read/discovery/resource | N/A |
| `get-publication` | `GetPublicationTool` | Unregistered read/discovery/resource | N/A |
| `get-social-account` | `GetSocialAccountTool` | Unregistered read/discovery/resource | N/A |
| `get-strategy` | `GetStrategyTool` | Unregistered read/discovery/resource | N/A |
| `get-task` | `GetTaskTool` | Unregistered read/discovery/resource | N/A |
| `get-workflow` | `GetWorkflowTool` | `workflow.get` | `GetWorkflow` |
| `get-workflow-execution` | `GetWorkflowExecutionTool` | `workflow.inspect` | `InspectWorkflowExecution` |
| `list-agent-assignments` | `ListAgentAssignmentsTool` | Unregistered read/discovery/resource | N/A |
| `list-agent-delegations` | `ListAgentDelegationsTool` | Unregistered read/discovery/resource | N/A |
| `list-agent-executions` | `ListExecutionTool` | Unregistered read/discovery/resource | N/A |
| `list-agents` | `ListAgentDescriptorTool` | Unregistered read/discovery/resource | N/A |
| `list-approval-requests` | `ListApprovalRequestTool` | Unregistered read/discovery/resource | N/A |
| `list-personas` | `ListPersonaTool` | Unregistered read/discovery/resource | N/A |
| `list-products` | `ListProductsTool` | `product.list` | `ListProducts` |
| `list-campaigns` | `ListCampaignTool` | Unregistered read/discovery/resource | N/A |
| `list-capabilities` | `ListCapabilitiesTool` | Unregistered read/discovery/resource | N/A |
| `list-channels` | `ListChannelTool` | Unregistered read/discovery/resource | N/A |
| `list-content-items` | `ListContentItemTool` | Unregistered read/discovery/resource | N/A |
| `list-content-series` | `ListContentSeriesTool` | Unregistered read/discovery/resource | N/A |
| `list-enterprises` | `ListEnterpriseTool` | Unregistered read/discovery/resource | N/A |
| `list-experts` | `ListExpertDescriptorTool` | Unregistered read/discovery/resource | N/A |
| `list-goals` | `ListGoalTool` | Unregistered read/discovery/resource | N/A |
| `list-initiatives` | `ListInitiativeTool` | Unregistered read/discovery/resource | N/A |
| `list-knowledge-indexes` | `ListKnowledgeIndexesTool` | Unregistered read/discovery/resource | N/A |
| `list-knowledge-units` | `ListKnowledgeUnitsTool` | Unregistered read/discovery/resource | N/A |
| `list-kpis` | `ListKpiTool` | Unregistered read/discovery/resource | N/A |
| `list-marketing-strategies` | `ListMarketingStrategyTool` | Unregistered read/discovery/resource | N/A |
| `list-memories` | `ListMemoryTool` | Unregistered read/discovery/resource | N/A |
| `list-objectives` | `ListObjectiveTool` | Unregistered read/discovery/resource | N/A |
| `list-plans` | `ListPlanTool` | Unregistered read/discovery/resource | N/A |
| `list-projects` | `ListProjectTool` | Unregistered read/discovery/resource | N/A |
| `list-publication-results` | `ListPublicationResultsTool` | Unregistered read/discovery/resource | N/A |
| `list-publications` | `ListPublicationTool` | Unregistered read/discovery/resource | N/A |
| `list-social-accounts` | `ListSocialAccountTool` | Unregistered read/discovery/resource | N/A |
| `list-strategies` | `ListStrategyTool` | Unregistered read/discovery/resource | N/A |
| `list-tasks` | `ListTaskTool` | Unregistered read/discovery/resource | N/A |
| `list-workflows` | `ListWorkflowsTool` | `workflow.discover` | `DiscoverWorkflows` |
| `mark-content-publication-ready` | `MarkContentPublicationReadyTool` | `marketing.content.publication-ready` | `MarkContentPublicationReady` |
| `plan-marketing` | `PlanMarketingTool` | `marketing.plan` | `PlanMarketing` |
| `publish-content` | `PublishContentTool` | `publication.publish` | `PublishContent` |
| `publish-workflow` | `PublishWorkflowTool` | `workflow.publish` | `PublishWorkflow` |
| `reconcile-publication-status` | `ReconcilePublicationStatusTool` | `publication.status.reconcile` | `ReconcilePublicationStatus` |
| `record-memory` | `RecordMemoryTool` | `memory.record` | `RecordMemory` |
| `request-approval` | `RequestApprovalTool` | `approval.request` | `ApprovalRequestCreate` |
| `resume-agent-execution` | `ResumeAgentExecutionTool` | Unregistered read/discovery/resource | N/A |
| `resume-workflow-execution` | `ResumeWorkflowExecutionTool` | `workflow.resume` | `ResumeWorkflowExecution` |
| `retrieve-enterprise-context` | `RetrieveEnterpriseContextTool` | `enterprise.context.retrieve` | `EnterpriseContextRetrieve` |
| `retrieve-knowledge` | `RetrieveKnowledgeTool` | `knowledge.retrieve` | `RetrieveKnowledge` |
| `retrieve-memory` | `RetrieveMemoryTool` | `memory.retrieve` | `RetrieveMemory` |
| `start-workflow-execution` | `StartWorkflowTool` | `workflow.execute` | `StartWorkflow` |
| `submit-content-for-review` | `SubmitContentForReviewTool` | `marketing.content.review` | `SubmitContentForReview` |
| `transition-agent-assignment` | `TransitionAgentAssignmentTool` | `agent.assignment.transition` | `TransitionAgentAssignment` |
| `transition-campaign` | `TransitionCampaignTool` | `marketing.campaign.lifecycle` | `CampaignLifecycle` |
| `transition-content-series` | `TransitionContentSeriesTool` | `marketing.content-series.lifecycle` | `ContentSeriesLifecycle` |
| `update-agent-assignment` | `UpdateAgentAssignmentTool` | `agent.assignment.update` | `UpdateAgentAssignment` |
| `update-persona` | `UpdatePersonaTool` | `marketing.persona.update` | `MarketingPersonaUpdate` |
| `update-campaign` | `UpdateCampaignTool` | `marketing.campaign.update` | `MarketingCampaignUpdate` |
| `update-channel` | `UpdateChannelTool` | `marketing.channel.update` | `MarketingChannelUpdate` |
| `update-content-item` | `UpdateContentItemTool` | `marketing.content.update` | `UpdateContentItem` |
| `update-content-series` | `UpdateContentSeriesTool` | `marketing.content-series.update` | `MarketingContentSeriesUpdate` |
| `update-enterprise-context` | `UpdateEnterpriseContextTool` | `enterprise.context.update` | `EnterpriseContextUpdate` |
| `update-knowledge-index` | `UpdateKnowledgeIndexTool` | `knowledge.index.update` | `UpdateKnowledgeIndex` |
| `update-knowledge-unit` | `UpdateKnowledgeUnitTool` | `knowledge.unit.update` | `UpdateKnowledgeUnit` |
| `update-memory` | `UpdateMemoryTool` | `memory.update` | `UpdateMemory` |
| `update-objective` | `UpdateObjectiveTool` | `marketing.objective.update` | `ObjectiveUpdate` |
| `update-project` | `UpdateProjectTool` | `marketing.project.update` | `ProjectUpdate` |
| `update-social-account` | `UpdateSocialAccountTool` | `marketing.social-account.update` | `SocialAccountUpdate` |
| `update-strategy` | `UpdateStrategyTool` | `strategy.update` | `UpdateStrategy` |
| `update-task` | `UpdateTaskTool` | `work.task.update` | `UpdateTask` |
| `update-workflow` | `UpdateWorkflowTool` | `workflow.update` | `UpdateWorkflow` |
| `verify-marketing-graph` | `VerifyMarketingGraphTool` | `marketing.graph.verify` | `VerifyMarketingGraph` |

Social-account creation and provider authorization are intentionally not generic MCP CRUD. OAuth initiation, callback validation, destination discovery/attachment, and provider revocation remain in the authenticated web lifecycle backed by `SocialAuthorizationService`. `disable-social-account` only disables a destination inside CR8OR; it does not claim to revoke the provider grant. Generic integration-connection creation is not exposed to Agents.

`publication.publish` and `publication.status.reconcile` are governed Capabilities available to persisted, published WorkflowVersion stages with the same stage input-contract validation used by other workflow Capabilities. Deterministic/interactive publishing does not require a model provider. Reconciliation returns only safe publication/result fields; provider payloads and credential references are excluded.


## Resources vs Tools

Resources provide authorized contextual information to an AI client and should represent useful business context rather than raw database structure.

Tools request explicit capabilities that may change state or initiate execution.

### Governed tool catalogue

The state-changing catalogue is intentionally limited to implemented, server-authorized capabilities:

| Tool | Capability | Operation | Purpose |
| --- | --- | --- |
| create-enterprise | enterprise.create | CreateEnterprise | Create an Enterprise under an organization where the authenticated actor has Enterprise creation authority. |
| create-enterprise-context | enterprise.context.create | CreateEnterpriseContext | Create business context for an authorized Enterprise; each Enterprise has one context record. |
| update-enterprise-context | enterprise.context.update | EnterpriseContextUpdate | Update an existing Enterprise Context while preserving Enterprise ownership and policy authorization. |
| create-task | work.task.create | CreateTask | Create a task under an authorized enterprise. |
| update-task | work.task.update | UpdateTask | Update an existing task without changing enterprise ownership. |
| create-strategy | strategy.create | CreateStrategy | Create a strategy under an authorized objective. |
| update-strategy | strategy.update | UpdateStrategy | Update an existing strategy without changing objective ownership. |
| approve-content-item | marketing.content.approve | ApproveContentItem | Approve in-review content after required governed approval. |
| create-content-item | marketing.content.create | CreateContentItem | Create draft Content Item state under an authorized enterprise. |
| update-content-item | marketing.content.update | UpdateContentItem | Revise draft or in-review Content Item state. |
| submit-content-for-review | marketing.content.review | SubmitContentForReview | Move draft content into the governed review state. |
| mark-content-publication-ready | marketing.content.publication-ready | MarkContentPublicationReady | Mark approved content publication-ready only with matching server-side approval. |
| request-approval | approval.request | ApprovalRequestCreate | Create an auditable approval request for an Agent capability and exact target context. |
| delegate-agent | `agent.delegate` | DelegateAgent | Delegate governed work between same-Enterprise Agent assignments through `AgentDelegationService`. |
| analyze-business-context | `business.analysis` | AnalyzeBusinessContext | Run Business Analysis Expert methodology against authorized Enterprise, Strategy, Work and Financial context. |
| plan-marketing | `marketing.plan` | PlanMarketing | Run Marketing Expert planning methodology against authorized Enterprise, Strategy and Knowledge context. |
| generate-financial-report | `finance.report.generate` | GenerateFinancialReport | Generate a historical, Enterprise-scoped financial report through `FinancialReportingService`; no generic financial CRUD is exposed. |
| generate-competitive-analysis-report | `marketing.competitor.report.generate` | GenerateCompetitiveAnalysisReport | Generate an immutable Enterprise-scoped competitive analysis from versioned competitor profiles and linked Knowledge evidence; persist result payload and provenance snapshot. |

For mutation tools, a human MCP call uses the existing Laravel policy for the target resource. An Agent-backed call must provide both agent_assignment_id and agent_execution_id; CR8OR resolves the MCP Tool through `CapabilityRegistry` first, then verifies that the execution belongs to the authenticated actor, assignment and enterprise before calling AgentCapabilityAuthorizer. Every state-changing MCP Tool has exactly one explicit Capability and one Operation. Generic mutation/transition fallbacks are not registered and cannot grant Agent authority; governed Tools must resolve directly through their explicit CapabilityRegistry definitions.

If the Agent permission requires approval, the mutation must also supply a valid approval_request_id whose organization, enterprise, assignment, execution, actor, capability and normalized target context match the current operation.

### Agent and Expert capability boundary

The current runtime capability graph is derived from the PHP Agent/Expert classes and the governed MCP registry rather than a persistent Capability model. Governed mutation Tools resolve through `CapabilityRegistry` to exactly one Capability and Operation before execution; specialized analysis, planning, delegation and Finance Tools retain their existing application-service boundaries.

Agent-backed analysis, planning and Finance calls must provide both `agent_assignment_id` and `agent_execution_id`; CR8OR verifies the execution, actor, assignment, Enterprise and declared capability before the application service runs. Human calls use the existing Enterprise/model policy boundary. MCP tool registration does not itself grant authority.

## Authentication Boundary

The remote MCP entry point is registered at /mcp through Laravel MCP and protected by Laravel Passport's auth:api guard. Laravel MCP's OAuth discovery and dynamic client-registration routes are registered through Mcp::oauthRoutes(). Passport provides the OAuth identity layer; CR8OR authorization remains a separate application concern.

## Authorization Boundary

Authorization is enforced by CR8OR server-side for the relevant actor, organization and capability/resource. MCP visibility is never the security boundary.

Capability authorization has three independent dimensions:

- **Availability:** the Agent or Expert runtime declares the Capability it can use. This is runtime metadata, not authority.
- **Authorization:** the Agent assignment must authorize the referenced Expert, and that Expert must own the Capability in the applicable organization and Enterprise scope.
- **Approval:** a separate approval decision is required when the Capability definition requires approval. Approval never follows merely from Capability availability or permission.

An Expert-owned Capability is authorized through its owning Expert; no separate AgentPermission authority exists. An Agent that is not authorized to use the Expert is denied, and an Expert cannot request a Capability it does not declare. Multiple Experts may reuse a Capability only where the registry explicitly permits that ownership model.

## Invocation Boundary

MCP Tool request → authentication → Capability authorization → validation → Operation → application/domain service → persistence/events/jobs → result

MCP handlers must not implement business rules that belong in application/domain services.

## Validation and Errors

MCP requests validate required input before invoking application services. Errors distinguish validation, authorization, unavailable-resource and application failures through Laravel MCP's error responses. A failed state-changing operation is never represented as successful merely because the transport succeeded.

## Auditability

State-changing MCP operations are attributable to the authenticated actor and, for Agent-backed operations, the Agent assignment, execution, capability and approval context.

## Direct Persistence Prohibition

MCP must not directly mutate Eloquent models, perform arbitrary database writes, or encode business invariants that belong in the application/domain service layer.

## Naming and Scope

Future resources and tools should use stable, capability-oriented business names rather than internal table names. The current Phase 4 catalogue is intentionally limited to verified Phase 1-3 capabilities; later product phases may add capabilities without weakening the same authorization boundary.

Phase 4.1 implements the protected MCP transport and authentication boundary. Phase 4.2 adds authorized contextual resources. Phase 4.3 adds the initial governed capability tools. Phase 4.4 adds the provider-neutral model adapter. Phase 4.5 adds governed Agent/Expert execution. Phase 4.6 adds operational audit, error and correlation contracts. Phase 4.7 verifies the complete Phase 4 implementation and reconciles documentation.

## Error and correlation contract

Every MCP tool execution receives a correlation identifier from `X-Correlation-ID` when supplied, or a generated UUID otherwise. MCP clients may also provide `cr8or.correlation_id` in request metadata. The identifier is returned in MCP error payloads and the HTTP response header and is propagated to AgentExecution and ApprovalRequest records where those records are created.

Tool failures are returned as MCP `isError` responses using the canonical provider-neutral CR8OR failure contract documented in `docs/failures.md`. The payload contains `type`, `code`, `message`, `retryable`, `correlation_id`, `diagnostic_id`, optional `operation`/`capability`/`tool` provenance, and safe structured `details`. Validation failures may contain field-level details. MCP serializes the application failure and must not invent transport-specific failure semantics.

Server-side logs contain correlation, actor, organization/execution and failure metadata only. Secrets, credentials, tokens and model prompt/context are not logged.

An AgentExecution records its correlation identifier, provider and external provider invocation identifier when available. Provider failure records the normalized failure code and transitions the execution to `failed`; a provider failure cannot produce a successful AgentDecision.

### Financial context

Phase 6.5 financial context is exposed through the existing authorization-aware Agent application context assembler rather than as a separate MCP financial resource. It is Enterprise-scoped, authorization-checked before assembly, and exposes derived/intentional context without unrestricted financial record access. A dedicated financial MCP resource is deferred unless a later product requirement establishes a distinct MCP contract.

## Agent runtime context and memory

The canonical Agent context pipeline may include the explicit `memory` context requirement. Memory is not exposed as a standalone MCP mutation surface or independent source of authority. When an Agent execution requests memory context, CR8OR resolves it through `AgentContextBuilder` and `MemoryContextProvider`, which enforce the current Agent Enterprise assignment, organization/Enterprise scope, bounded retrieval and provenance metadata. Episodic and semantic memory remain derived Agent runtime records and do not replace Knowledge, business state or execution history.

## Discovery tools

The foundational read/discovery layer exposes bounded, authorization-aware list/get tools for:

| Domain | Tools |
| --- | --- |
| Organization | `list-enterprises`, `get-enterprise` |
| Strategy | `list-objectives`, `get-objective`, `list-strategies`, `get-strategy` |
| Work | `list-tasks`, `get-task` |
| Intelligence | `list-agents`, `get-agent`, `list-experts`, `get-expert-descriptor`, `list-capabilities`, `get-capability` |
| Content | `list-campaigns`, `get-campaign`, `list-content-series`, `get-content-series`, `list-content-items`, `get-content-item`, `list-personas`, `get-persona`, `list-channels`, `get-channel` |
| Operations | `list-agent-executions`, `get-agent-execution`, `list-approval-requests`, `get-approval-request` |

List tools return `result.items` plus a bounded `pagination` object containing `page`, `per_page`, `total`, and `last_page`. They accept `per_page` (1-50), `page`, and, where applicable, enterprise, status, parent-resource, and name/title/slug search filters. All list queries are scoped to organizations accessible to the authenticated actor before pagination.

Get tools return a single `result` object and first resolve the requested record inside the actor's authorized organization/enterprise scope, followed by the resource policy's `view` authorization.

`get-enterprise` is the canonical enterprise identity lookup. It accepts either the internal `id` or a canonical `slug`; named-enterprise workflows should use `slug`. Human-entered values such as `valid.guide` are normalized to the canonical slug `valid-guide`. When both `id` and `slug` are supplied, they must identify the same Enterprise. Slug-only resolution is rejected when the actor can access multiple organizations containing the same slug. The result always includes the authoritative `id` and `slug`.

Agent and Expert descriptors use their existing policy boundary. Capability discovery is runtime-derived rather than backed by a persistent Capability model: a capability identifier is the stable capability string exposed by enabled Agent/Expert runtimes, and `get-capability` accepts that string as its `id`. This avoids inventing a second persistent source of truth for runtime capability metadata.

Discovery responses expose stable identifiers, human-readable names/titles where the underlying resource has them, relevant status and parent identifiers, and timestamps. They do not return raw Eloquent models or persistence internals.

A typical discovery-first workflow is:

1. `list-enterprises`
2. `list-objectives` with the enterprise scope
3. `create-strategy` using the discovered objective id
4. `get-strategy` using the resulting strategy id
5. Discover related work, content, execution, or approval records as required.

Discovery tools use the same Laravel policy and organization/enterprise authorization boundaries as the application. They do not grant mutation authority and do not bypass application services or policies.


## Domain and lifecycle operation surface

The governed mutation surface now covers the core planning, content, work, and integration context needed by Agents:

| Domain | Tools / Operations |
| --- | --- |
| Organization / Enterprise context | `create-enterprise`, `create-enterprise-context` |
| Planning | `create-objective`, `update-objective`, existing `create-strategy`, `update-strategy` |
| Campaigns | `create-campaign`, `update-campaign`, `transition-campaign` |
| Content series | `create-content-series`, `update-content-series`, `transition-content-series` |
| Personas | `create-persona`, `update-persona`, `archive-persona` |
| Channels | `create-channel`, `update-channel`, `archive-channel` |
| Social accounts | `list-social-accounts`, `get-social-account`, `update-social-account`, `disable-social-account` |
| Projects | `list-projects`, `get-project`, `create-project`, `update-project` |
| Publishing | `publish-content`, `get-publication`, `list-publications`, `list-publication-results`, `reconcile-publication-status` |
| Existing content lifecycle | `create-content-item`, `update-content-item`, `submit-content-for-review`, `approve-content-item`, `mark-content-publication-ready`, `request-approval` |

Campaign creation is intentionally bound to the existing `MarketingStrategy` relationship in the current domain model. Marketing strategies are therefore discoverable through `list-marketing-strategies` and `get-marketing-strategy`; the MCP layer does not invent a second strategy persistence model.

Project and objective relationships remain discoverable through `list/get` Tools for goals, KPIs, plans, and initiatives. Cross-enterprise relationship references are rejected in the domain service before persistence.

Social-account discovery returns provider metadata and external identifiers only. Generic account creation and generic connection CRUD are not exposed through MCP. OAuth initiation, callback validation, destination discovery/attachment, token refresh, and provider revocation remain in the authenticated web lifecycle backed by `SocialAuthorizationService`. `update-social-account` changes only display metadata/channel assignment; `disable-social-account` deactivates the destination inside CR8OR and does not claim to revoke the provider grant. Raw OAuth tokens, credential references, and provider payloads are excluded from ordinary MCP responses. External IDs remain opaque and the `(provider, external_id)` uniqueness constraint remains authoritative.

Lifecycle Operations use the domain model transition methods where those transitions exist. They do not permit arbitrary status writes to bypass the model's transition rules. Human approval remains a separate governed step; MCP mutation authority does not imply approval authority.

All state-changing Operations continue through the existing authorization boundary and application/domain services. The MCP layer remains a transport and validation boundary rather than a second business-rule implementation.

## Knowledge Retrieval Boundary

Knowledge retrieval is an application-service capability rather than direct MCP persistence or database access. The canonical KnowledgeRetrievalService enforces Enterprise authorization before invoking a provider and provenance normalization after provider execution. Lexical, semantic and hybrid retrieval share the same provider-neutral contract.

MCP resources and future retrieval tools must use this boundary rather than querying KnowledgeIndexUnit, KnowledgeEmbedding or authoritative Knowledge records directly. Retrieved Knowledge remains bounded and provenance-aware when consumed by the Agent context pipeline.
## Knowledge resource and retrieval surface

Phase 12 adds a dedicated Knowledge resource surface while preserving the existing authoritative/derived boundary.

| Domain | Tool | Canonical Operation | Authority |
| --- | --- | --- | --- |
| Knowledge index | create-knowledge-index | CreateKnowledgeIndex | Resource operation; rebuilds derived index state from authoritative Knowledge |
| Knowledge index | get-knowledge-index | GetKnowledgeIndex | Authorized resource query |
| Knowledge index | list-knowledge-indexes | ListKnowledgeIndexes | Authorized bounded resource query |
| Knowledge index | update-knowledge-index | UpdateKnowledgeIndex | Resource operation; reindexes authoritative Knowledge |
| Knowledge unit | create-knowledge-unit | CreateKnowledgeUnit | Resource operation; materializes a derived unit from authoritative Knowledge |
| Knowledge unit | get-knowledge-unit | GetKnowledgeUnit | Authorized resource query |
| Knowledge unit | list-knowledge-units | ListKnowledgeUnits | Authorized bounded resource query |
| Knowledge unit | update-knowledge-unit | UpdateKnowledgeUnit | Resource operation; rebuilds the unit from authoritative Knowledge |
| Knowledge unit | archive-knowledge-unit | ArchiveKnowledgeUnit | Resource operation; archives the derived representation without deleting authoritative Knowledge |
| Retrieval | retrieve-knowledge | RetrieveKnowledge | Governed knowledge.retrieve capability |

Knowledge Index Records and Knowledge Index Units are derived search representations. Their content is never authoritative business state and MCP resource operations do not permit arbitrary persistence writes to those records. Create/update operations rebuild them through KnowledgeIndexingService; archive marks the derived representation removed while retaining historical state.

retrieve-knowledge supports lexical, semantic, and hybrid modes, bounded results (1-50), optional minimum relevance, correlation identifiers, Enterprise authorization and provenance normalization. Agent-backed retrieval requires both agent_assignment_id and agent_execution_id and is authorized through McpCapabilityAuthorizer.

Cross-Enterprise access is rejected at the application boundary. MCP handlers do not query or mutate Knowledge persistence directly.
## Memory resource and retrieval surface

Phase 12 exposes the existing durable Agent Memory stores through a single enterprise-scoped MCP resource contract while keeping episodic and semantic lifecycle rules distinct.

| Domain | Tool | Canonical Operation | Authority |
| --- | --- | --- | --- |
| Memory | create-memory | CreateMemory | Resource operation; requires explicit source execution provenance |
| Memory | get-memory | GetMemory | Authorized resource query |
| Memory | list-memories | ListMemory | Authorized bounded resource query/search |
| Memory | update-memory | UpdateMemory | Resource operation; semantic Memory only, with version history |
| Memory | archive-memory | ArchiveMemory | Resource operation; semantic Memory lifecycle |
| Memory | retrieve-memory | RetrieveMemory | Governed memory.retrieve capability |
| Memory | record-memory | RecordMemory | Governed memory.record capability |

Episodic Memory remains an immutable concise record of a meaningful Agent execution event. Semantic Memory supports explicit versioned updates and archival. Durable writes require explicit source execution provenance; transient Agent context is never silently persisted as Memory.

memory.retrieve is bounded and Enterprise/Agent scoped. memory.record requires an explicit persist=true request and uses the same application/domain Memory services as resource creation. Neither capability exposes hidden chain-of-thought.

All Memory MCP handlers are adapters over Operations and application services. They do not mutate Eloquent models directly.
## Agent Assignment resource and lifecycle

Agent Assignments expose their existing durable lifecycle through MCP as resource operations. The surface is Enterprise-scoped and uses the existing `AgentAssignmentPolicy` and `AgentAssignmentService`; MCP registration does not itself grant management authority.

| Tool | Operation | Purpose |
| --- | --- | --- |
| create-agent-assignment | CreateAgentAssignment | Create an idempotent Enterprise-scoped assignment with objective, requirements and context |
| get-agent-assignment | GetAgentAssignment | Read one assignment within Enterprise scope |
| list-agent-assignments | ListAgentAssignments | Bounded listing with Agent and lifecycle filters |
| update-agent-assignment | UpdateAgentAssignment | Update assignment definition while preserving lifecycle state rules |
| transition-agent-assignment | TransitionAgentAssignment | Apply only explicit state-machine transitions |

Lifecycle transitions are `draft -> ready -> running -> paused -> running` plus terminal `completed`, `failed`, and `cancelled` paths as defined by the domain model. Starting an assignment records `started_at`; terminal transitions record `completed_at`; completed and cancelled assignments are disabled.

Management operations require the existing assignment management policy. Cross-Enterprise access is rejected before the resource is returned. The MCP tools remain thin adapters over Operations and the existing Assignment service.


## Agent Execution resource and runtime surface

Agent Executions are durable runtime resources linked to an Enterprise, Agent Assignment and Agent. The MCP surface uses the existing `AgentExecutionService` and queue/job runtime; MCP does not implement a second execution engine.

| Tool | Canonical action | Purpose |
| --- | --- | --- |
| `execute-agent` | `agent.execute` / `ExecuteAgent` | Governed start of an Agent Execution |
| `create-agent-execution` | `CreateAgentExecution` | Resource-level durable execution start |
| `get-agent-execution` | `GetAgentExecution` | Retrieve durable status, input, result and runtime history |
| `list-agent-executions` | `ListAgentExecutions` | Bounded Enterprise-scoped execution listing |
| `resume-agent-execution` | `ResumeAgentExecution` | Resume waiting/paused/delegated execution through the canonical runtime |
| `cancel-agent-execution` | `CancelAgentExecution` | Cancel an active execution through its lifecycle rules |

Execution starts are idempotent by organization and idempotency key. Starts through the resource surface require the Assignment to be `ready` or `running`; invalid Assignment state is rejected before dispatch. Execution failures remain durable with structured failure code/category, while structured results are persisted without hidden chain-of-thought.

All execution starts require an explicit `mode`: `interactive` or `autonomous`. Interactive starts return a durable continuation contract. ChatGPT submits each next structured reasoning result through `continue-agent-execution`; CR8OR validates the exact execution step and idempotency key, re-authorizes Agent → Expert → Capability and executes the resulting Operations. Interactive never invokes a ModelProvider and normal continuation does not require a worker. `waiting_for_input` and `waiting_for_approval` are explicit human gates. The MCP host controls whether and how the next model turn is chained. Autonomous follows the existing queued Agent → Expert → Capability → Operation → ModelProvider reasoning loop. Resume and approval semantics preserve the persisted execution mode and never convert interactive work into autonomous execution.

## Complete MCP surface contract

The CR8OR server registers every concrete MCP Tool class under `app/Mcp/Tools`; a contract test fails if a concrete tool is added without server registration. Governed action tools resolve through `CapabilityRegistry`, while resource/discovery tools resolve to canonical resource Operations or query services. The server also exposes the Enterprise, Strategy, Knowledge and Work context resources through URI templates.

The live surface is intentionally split into: discovery/read resources, domain resource CRUD and lifecycle tools, governed capability tools, and Agent runtime tools. This keeps direct MCP clients on the same authorization, approval, lifecycle and provenance paths as internal execution.


## Runtime E2E audit

`E2E-TEST-20260928` is the canonical Phase 12 runtime audit. It exercises the live MCP contract rather than application services directly: Enterprise and Enterprise Context creation, Knowledge indexing/retrieval, Memory creation/retrieval, Agent Assignment and Execution resources, Agent-to-Agent delegation, Expert-backed child execution, governed Content creation, and human review.

The audit also verifies duplicate creation, invalid relationships, missing execution input, invalid lifecycle transitions, and cross-Enterprise access. It writes a machine-readable report to `storage/app/e2e/E2E-TEST-20260928.json` and prints the complete report to the test output. The test database is isolated by the repository Feature-test `RefreshDatabase` harness.

Delegation inspection is exposed through `get-agent-delegation` and `list-agent-delegations`, allowing direct MCP clients to reconstruct the parent execution → delegation → child execution relationship without database access. Delegation may explicitly provide registered Expert slugs for the child execution; the canonical `AgentDelegationService` passes those slugs into the Agent execution path.

### Authoritative Knowledge creation

`create-knowledge-item` is the canonical MCP resource operation for creating an Enterprise-scoped authoritative Knowledge Item and its initial immutable Knowledge Version. It exists so a direct MCP client can construct the Knowledge branch before invoking the derived Knowledge Index/Unit lifecycle; indexing remains a derived representation and never becomes the authoritative content store.
## Enterprise Context Capability coverage

Enterprise Context is available through both `enterprise.context.create` and `enterprise.context.retrieve`. The retrieval Capability resolves to `EnterpriseContextRetrieve`, which is also the application Operation consumed by the Enterprise Context MCP resource. The application contract returns the canonical Enterprise/Context representation used by downstream Agent and Expert composition; the MCP resource retains its existing transport response shape and does not expose persistence metadata.
## Knowledge Capability boundary

The state-changing Knowledge MCP adapters `create-knowledge-item`, `create-knowledge-index`, and `create-knowledge-unit` resolve through the Capability Registry and `CapabilityInvocationService`. They do not execute Knowledge Operations directly. The application-native Knowledge lifecycle is therefore:

**Capability → Operation → Knowledge service → Persistence / derived index**

`retrieve-knowledge` already uses the same governed boundary. MCP remains an adapter and is not required for Capability-native Knowledge execution.


## Capability-native E2E is separate from MCP

`E2E-CAPABILITY-20260928` is the application-path validation for Phase 12. It must not invoke MCP CRUD/action Tools. MCP remains a transport/interface adapter and is validated separately by the MCP E2E contract. The Capability E2E starts at `CapabilityInvocationService` and verifies persisted relationships and governance independently of the MCP surface.
### Exception translation boundary

MCP tools delegate exception-to-failure mapping to `App\\Services\\FailureTranslator`. MCP is a transport boundary only: it serializes the canonical failure contract and never invents a second exception taxonomy. Unknown exceptions become `internal.unexpected`; internal diagnostics remain server-side.

### Capability failure provenance

Governed MCP tools resolve their Capability → Operation mapping from CapabilityRegistry. Operation failures are translated through the canonical failure boundary with operation and capability provenance before MCP serialization. Authorization and approval failures remain distinct failure categories and are not collapsed into generic Operation failures.

### Diagnostic workflow

MCP responses expose the correlation ID, diagnostic ID and canonical failure code needed for support correlation. They do not expose exception classes, traces, credentials, provider payloads or model context. Operators use the correlation ID to locate the structured failure event and the diagnostic ID to locate the sanitized internal diagnostic record.

## Named-enterprise strategy workflow

When a strategy request names an Enterprise rather than providing an internal id, resolve the Enterprise first with `get-enterprise` using its canonical slug. For example, `valid.guide` normalizes to `valid-guide`. Continue the strategy workflow using the resolved Enterprise id and preserve the resolved id/slug pair through Agent assignment and execution context. Never derive an Enterprise id by inference or silently substitute a different Enterprise.

## Competitive analysis reports

The generate-competitive-analysis-report tool delegates to the governed marketing.competitor.report.generate Capability and GenerateCompetitiveAnalysisReport Operation. It accepts an Enterprise ID and optional list of competitor profile-version IDs; an omitted or empty list selects current versions. The operation enforces Enterprise scope and creates an immutable competitive_analysis Report, structured result payload, and provenance snapshot. The report uses the deterministic recorded-profile methodology 1.0; it does not infer missing facts. See [competitive-analysis-reporting.md](competitive-analysis-reporting.md).