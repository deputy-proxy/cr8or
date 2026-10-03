# CR8OR Architecture

## Unified execution model

The repository-wide verification report for this architecture is maintained in `docs/architecture/unified-execution-audit-2026-10-03.md`.

CR8OR separates orchestration, governed business execution, administration, and external-result reconciliation.

### Orchestrated business work

```
Agent → Expert → Capability → Operation → Application/Domain → State
Workflow → Stage → Expert → Capability → Operation → Application/Domain → State
```

Agents reason and orchestrate. Experts provide governed capability ownership within Agent execution. Workflows are persisted, deterministic orchestration. Capabilities are the governed business boundary. Operations perform the concrete business work.

### Direct business entry points

```
MCP business Tool → Capability → Operation → Application/Domain → State
Command Webhook → Capability → Operation → Application/Domain → State
```

Every direct business MCP Tool maps to exactly one Capability and exactly one Operation, and executes through the Capability boundary. A Capability may exist without an MCP Tool, and an internal Operation does not automatically become public.

### Explicit exceptions

```
Filament → Laravel application/model state
External Provider → Integration Webhook → IntegrationResultService → Reconciliation
```

Filament intentionally remains the native administrative interface and is not routed through MCP or the Capability boundary. External-result webhooks are reconciliation inputs, not business command entry points.

### Lifecycle MCP Tools

Agent/workflow lifecycle MCP Tools are orchestration controls rather than business Capability Tools. They use the explicit namespaces:

- `mcp_agent_*`
- `mcp_workflow_*`

They are not required to have a business Capability mapping.

## Filament navigation taxonomy

Filament navigation groups are an information-architecture concern. They organize the administrative interface and do not define domain ownership, authorization, persistence boundaries, or execution authority. Server-side policies remain authoritative for resource access and CRUD behavior, and the Filament UI must remain consistent with those policies.

The current navigation taxonomy is aligned with CR8OR application architecture. The Agentic Flow group reflects the Agent → Expert → Capability architecture; it does not create or imply a direct Agent → Capability relationship. The app/Filament/Resources/Concerns directory contains support traits and is not a resource group.

### Group order and resource mapping

Resource navigation is ordered deliberately within each group to communicate CR8OR domain flow. Group order is controlled separately by the panel navigation-group definition. Resource order is controlled by each Resource class through an explicit `$navigationSort` value. The order below is the canonical information architecture.

### Organization & Enterprise Scope

Organization identity, membership, enterprise boundaries, and organizational assignments.

| Position | Resource | Filament class location |
| ---: | --- | --- |
| 1 | Organizations | app/Filament/Resources/Organizations/*Resource.php |
| 2 | Users | app/Filament/Resources/Users/*Resource.php |
| 3 | Memberships | app/Filament/Resources/Memberships/*Resource.php |
| 4 | Enterprises | app/Filament/Resources/Enterprises/*Resource.php |
| 5 | Assignments | app/Filament/Resources/Assignments/*Resource.php |

### Enterprise Context

Enterprise context and intent, measurable outcomes, strategy, planning, work, decisions, and market/product context.

| Position | Resource | Filament class location |
| ---: | --- | --- |
| 1 | EnterpriseContexts | app/Filament/Resources/EnterpriseContexts/*Resource.php |
| 2 | Visions | app/Filament/Resources/Visions/*Resource.php |
| 3 | Missions | app/Filament/Resources/Missions/*Resource.php |
| 4 | Goals | app/Filament/Resources/Goals/*Resource.php |
| 5 | Objectives | app/Filament/Resources/Objectives/*Resource.php |
| 6 | Kpis | app/Filament/Resources/Kpis/*Resource.php |
| 7 | MetricDefinitions | app/Filament/Resources/MetricDefinitions/*Resource.php |
| 8 | Strategies | app/Filament/Resources/Strategies/*Resource.php |
| 9 | Plans | app/Filament/Resources/Plans/*Resource.php |
| 10 | Initiatives | app/Filament/Resources/Initiatives/*Resource.php |
| 11 | Projects | app/Filament/Resources/Projects/*Resource.php |
| 12 | Milestones | app/Filament/Resources/Milestones/*Resource.php |
| 13 | Tasks | app/Filament/Resources/Tasks/*Resource.php |
| 14 | WorkItems | app/Filament/Resources/WorkItems/*Resource.php |
| 15 | Decisions | app/Filament/Resources/Decisions/*Resource.php |
| 16 | EnterpriseDecisions | app/Filament/Resources/EnterpriseDecisions/*Resource.php |
| 17 | Competitors | app/Filament/Resources/Competitors/*Resource.php |
| 18 | Products | app/Filament/Resources/Products/*Resource.php |

### Knowledge Management

Knowledge context and acquisition, documents, normalized knowledge, versioning, references, indexing, and embeddings.

| Position | Resource | Filament class location |
| ---: | --- | --- |
| 1 | KnowledgeContexts | app/Filament/Resources/KnowledgeContexts/*Resource.php |
| 2 | KnowledgeSources | app/Filament/Resources/KnowledgeSources/*Resource.php |
| 3 | KnowledgeDocuments | app/Filament/Resources/KnowledgeDocuments/*Resource.php |
| 4 | KnowledgeItems | app/Filament/Resources/KnowledgeItems/*Resource.php |
| 5 | KnowledgeVersions | app/Filament/Resources/KnowledgeVersions/*Resource.php |
| 6 | KnowledgeSpecifications | app/Filament/Resources/KnowledgeSpecifications/*Resource.php |
| 7 | KnowledgeReferences | app/Filament/Resources/KnowledgeReferences/*Resource.php |
| 8 | KnowledgeIndexRecords | app/Filament/Resources/KnowledgeIndexRecords/*Resource.php |
| 9 | KnowledgeIndexUnits | app/Filament/Resources/KnowledgeIndexUnits/*Resource.php |
| 10 | KnowledgeEmbeddings | app/Filament/Resources/KnowledgeEmbeddings/*Resource.php |

### Agentic Flow

Agents, Experts, runtime policy, assignments, execution state, delegation, decisions, memory, and approvals.

| Position | Resource | Filament class location |
| ---: | --- | --- |
| 1 | AgentDescriptors | app/Filament/Resources/AgentDescriptors/*Resource.php |
| 2 | ExpertDescriptors | app/Filament/Resources/ExpertDescriptors/*Resource.php |
| 3 | AgentRuntimePolicies | app/Filament/Resources/AgentRuntimePolicies/*Resource.php |
| 4 | AgentAssignments | app/Filament/Resources/AgentAssignments/*Resource.php |
| 5 | AgentExecutions | app/Filament/Resources/AgentExecutions/*Resource.php |
| 6 | AgentExecutionSteps | app/Filament/Resources/AgentExecutionSteps/*Resource.php |
| 7 | AgentExecutionEventRecords | app/Filament/Resources/AgentExecutionEventRecords/*Resource.php |
| 8 | AgentDelegations | app/Filament/Resources/AgentDelegations/*Resource.php |
| 9 | AgentDecisions | app/Filament/Resources/AgentDecisions/*Resource.php |
| 10 | AgentEpisodicMemories | app/Filament/Resources/AgentEpisodicMemories/*Resource.php |
| 11 | AgentSemanticMemories | app/Filament/Resources/AgentSemanticMemories/*Resource.php |
| 12 | AgentSemanticMemoryVersions | app/Filament/Resources/AgentSemanticMemoryVersions/*Resource.php |
| 13 | ApprovalPolicies | app/Filament/Resources/ApprovalPolicies/*Resource.php |
| 14 | ApprovalRequests | app/Filament/Resources/ApprovalRequests/*Resource.php |
| 15 | ApprovalDecisions | app/Filament/Resources/ApprovalDecisions/*Resource.php |

### Workflow Flow

Persisted workflow definitions, versions, stages, dependencies, executions, and jobs.

| Position | Resource | Filament class location |
| ---: | --- | --- |
| 1 | Workflows | app/Filament/Resources/Workflows/*Resource.php |
| 2 | WorkflowVersions | app/Filament/Resources/WorkflowVersions/*Resource.php |
| 3 | WorkflowStages | app/Filament/Resources/WorkflowStages/*Resource.php |
| 4 | Dependencies | app/Filament/Resources/Dependencies/*Resource.php |
| 5 | WorkflowExecutions | app/Filament/Resources/WorkflowExecutions/*Resource.php |
| 6 | Executions | app/Filament/Resources/Executions/*Resource.php |
| 7 | Jobs | app/Filament/Resources/Jobs/*Resource.php |

### Marketing

Marketing strategy, campaigns, content planning, audiences, channels, production, generation/rendering, and publishing.

| Position | Resource | Filament class location |
| ---: | --- | --- |
| 1 | MarketingStrategies | app/Filament/Resources/MarketingStrategies/*Resource.php |
| 2 | Campaigns | app/Filament/Resources/Campaigns/*Resource.php |
| 3 | ContentSeries | app/Filament/Resources/ContentSeries/*Resource.php |
| 4 | Audiences | app/Filament/Resources/Audiences/*Resource.php |
| 5 | Channels | app/Filament/Resources/Channels/*Resource.php |
| 6 | SocialAccounts | app/Filament/Resources/SocialAccounts/*Resource.php |
| 7 | ContentItems | app/Filament/Resources/ContentItems/*Resource.php |
| 8 | Scripts | app/Filament/Resources/Scripts/*Resource.php |
| 9 | Assets | app/Filament/Resources/Assets/*Resource.php |
| 10 | AssetVersions | app/Filament/Resources/AssetVersions/*Resource.php |
| 11 | MediaMetadata | app/Filament/Resources/MediaMetadata/*Resource.php |
| 12 | GenerationRequests | app/Filament/Resources/GenerationRequests/*Resource.php |
| 13 | GenerationJobs | app/Filament/Resources/GenerationJobs/*Resource.php |
| 14 | RenderRequests | app/Filament/Resources/RenderRequests/*Resource.php |
| 15 | RenderJobs | app/Filament/Resources/RenderJobs/*Resource.php |
| 16 | RenderOutputs | app/Filament/Resources/RenderOutputs/*Resource.php |
| 17 | Transformations | app/Filament/Resources/Transformations/*Resource.php |
| 18 | Publications | app/Filament/Resources/Publications/*Resource.php |
| 19 | PublicationSchedules | app/Filament/Resources/PublicationSchedules/*Resource.php |
| 20 | PublishingJobs | app/Filament/Resources/PublishingJobs/*Resource.php |
| 21 | PublicationResults | app/Filament/Resources/PublicationResults/*Resource.php |

### Finance

Financial context, counterparties, financial activity, planning/control structures, statements/invoices, and derived financial reporting.

| Position | Resource | Filament class location |
| ---: | --- | --- |
| 1 | FinancialAccounts | app/Filament/Resources/FinancialAccounts/*Resource.php |
| 2 | FinancialPeriods | app/Filament/Resources/FinancialPeriods/*Resource.php |
| 3 | TransactionCategories | app/Filament/Resources/TransactionCategories/*Resource.php |
| 4 | Customers | app/Filament/Resources/Customers/*Resource.php |
| 5 | Partners | app/Filament/Resources/Partners/*Resource.php |
| 6 | Revenues | app/Filament/Resources/Revenues/*Resource.php |
| 7 | Expenses | app/Filament/Resources/Expenses/*Resource.php |
| 8 | Transactions | app/Filament/Resources/Transactions/*Resource.php |
| 9 | Budgets | app/Filament/Resources/Budgets/*Resource.php |
| 10 | Statements | app/Filament/Resources/Statements/*Resource.php |
| 11 | StatementEntries | app/Filament/Resources/StatementEntries/*Resource.php |
| 12 | Invoices | app/Filament/Resources/Invoices/*Resource.php |
| 13 | FinancialReports | app/Filament/Resources/FinancialReports/*Resource.php |
| 14 | BusinessHealthResults | app/Filament/Resources/BusinessHealthResults/*Resource.php |

### Reporting & Analytics

Cross-domain reports, point-in-time snapshots, and recorded report metric values.

| Position | Resource | Filament class location |
| ---: | --- | --- |
| 1 | Reports | app/Filament/Resources/Reports/*Resource.php |
| 2 | ReportSnapshots | app/Filament/Resources/ReportSnapshots/*Resource.php |
| 3 | ReportMetricValues | app/Filament/Resources/ReportMetricValues/*Resource.php |

### Integrations & External Systems

External connections and resources, integration execution/results, and command webhook delivery records.

| Position | Resource | Filament class location |
| ---: | --- | --- |
| 1 | IntegrationConnections | app/Filament/Resources/IntegrationConnections/*Resource.php |
| 2 | ExternalResources | app/Filament/Resources/ExternalResources/*Resource.php |
| 3 | IntegrationJobs | app/Filament/Resources/IntegrationJobs/*Resource.php |
| 4 | IntegrationResults | app/Filament/Resources/IntegrationResults/*Resource.php |
| 5 | CommandWebhookDeliveries | app/Filament/Resources/CommandWebhookDeliveries/*Resource.php |

### Navigation rules

- The nine top-level navigation groups retain this exact order: Organization & Enterprise Scope, Enterprise Context, Knowledge Management, Agentic Flow, Workflow Flow, Marketing, Finance, Reporting & Analytics, Integrations & External Systems.
- Every current Filament Resource must define an explicit numeric `$navigationSort`.
- Sort values are unique within each group and use stable increments of 10 so future resources can be inserted deliberately.
- Navigation sorting is an information-architecture concern. It does not define domain ownership, authorization, persistence boundaries, or execution authority.
- New resources must be assigned deliberately to a documented position in the appropriate group rather than inheriting alphabetical or incidental ordering.
- The Agentic Flow sequence preserves the Agent → Expert → Capability architecture and does not introduce a direct Agent → Capability relationship.
- `app/Filament/Resources/Concerns` contains support traits and is not a resource group.


- The top-level order is fixed as shown above.
- Every actual Filament Resource belongs to exactly one group.
- All Knowledge* resources belong exclusively to Knowledge Management.
- MarketingStrategy belongs to Marketing.
- Competitor and Product belong to Enterprise Context.
- Financial resources, including the current Budget resource, belong to Finance. Budget is included because it is an actual current resource in the verified repository inventory.
- AgentCollaborationReport is a Filament Page rather than a Resource and is placed under Reporting & Analytics so it does not recreate an obsolete navigation group.
- Concerns contains reusable Filament support traits and is never treated as a resource group.
- Navigation grouping must never replace, bypass, or weaken policy authorization.
- Resource labels, icons, routes, forms, tables, actions, scopes, and persistence behavior are independent of navigation grouping.

## Governed business boundary

`CapabilityInvocationService` is the common execution substrate. It resolves the authoritative Capability registry, applies the relevant authorization and governance context, and invokes the mapped Operation. Entry points provide their legitimate context, but none owns a parallel business execution engine.

Authorization, approval, correlation, idempotency and normalized failure semantics remain explicit. Approval is independent from Expert ownership. An authenticated command Webhook credential is not itself an approval grant.

## Architecture invariants

- Agents do not own Capabilities directly. Agent authority flows through assigned Experts.
- Workflows do not call Operations by class name or invoke MCP business Tools as a shortcut.
- MCP business Tools do not contain independent business execution paths.
- Command Webhooks do not accept arbitrary Operation classes or model mutation instructions.
- Filament continues using normal Laravel/Eloquent application behavior.
- Integration-result webhooks continue through the integration reconciliation boundary.
- Deterministic Workflow execution does not require a ModelProvider.
- Workflows exist as persisted database state, not hardcoded runtime definitions.

## Execution proof

The repository includes cross-entry regression coverage proving a representative `work.item.update` Operation can be reached through direct MCP, Agent-backed Capability invocation, deterministic Workflow execution, and command Webhook execution while preserving Enterprise scope and authoritative state. Integration-result reconciliation and lifecycle MCP namespace separation are covered as explicit exceptions.

See `docs/architecture/capability-execution-contract.md` for the detailed request metadata, authorization, approval, idempotency and failure contract.