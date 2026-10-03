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

### Organization & Enterprise Scope

Organization identity, membership, enterprise boundaries, and authorization scope.

| Resource | Filament class location |
| --- | --- |
| Organizations | app/Filament/Resources/Organizations/*Resource.php |
| Users | app/Filament/Resources/Users/*Resource.php |
| Memberships | app/Filament/Resources/Memberships/*Resource.php |
| Enterprises | app/Filament/Resources/Enterprises/*Resource.php |
| Assignments | app/Filament/Resources/Assignments/*Resource.php |

### Enterprise Context

Enterprise goals, strategy, plans, work, decisions, and business context.

| Resource | Filament class location |
| --- | --- |
| EnterpriseContexts | app/Filament/Resources/EnterpriseContexts/*Resource.php |
| Visions | app/Filament/Resources/Visions/*Resource.php |
| Missions | app/Filament/Resources/Missions/*Resource.php |
| Goals | app/Filament/Resources/Goals/*Resource.php |
| Objectives | app/Filament/Resources/Objectives/*Resource.php |
| Kpis | app/Filament/Resources/Kpis/*Resource.php |
| MetricDefinitions | app/Filament/Resources/MetricDefinitions/*Resource.php |
| Strategies | app/Filament/Resources/Strategies/*Resource.php |
| Plans | app/Filament/Resources/Plans/*Resource.php |
| Initiatives | app/Filament/Resources/Initiatives/*Resource.php |
| Projects | app/Filament/Resources/Projects/*Resource.php |
| Milestones | app/Filament/Resources/Milestones/*Resource.php |
| Tasks | app/Filament/Resources/Tasks/*Resource.php |
| WorkItems | app/Filament/Resources/WorkItems/*Resource.php |
| Decisions | app/Filament/Resources/Decisions/*Resource.php |
| EnterpriseDecisions | app/Filament/Resources/EnterpriseDecisions/*Resource.php |
| Competitors | app/Filament/Resources/Competitors/*Resource.php |
| Products | app/Filament/Resources/Products/*Resource.php |

### Knowledge Management

Knowledge acquisition, documents, versions, references, indexing, and embeddings.

| Resource | Filament class location |
| --- | --- |
| KnowledgeContexts | app/Filament/Resources/KnowledgeContexts/*Resource.php |
| KnowledgeSources | app/Filament/Resources/KnowledgeSources/*Resource.php |
| KnowledgeDocuments | app/Filament/Resources/KnowledgeDocuments/*Resource.php |
| KnowledgeItems | app/Filament/Resources/KnowledgeItems/*Resource.php |
| KnowledgeVersions | app/Filament/Resources/KnowledgeVersions/*Resource.php |
| KnowledgeSpecifications | app/Filament/Resources/KnowledgeSpecifications/*Resource.php |
| KnowledgeReferences | app/Filament/Resources/KnowledgeReferences/*Resource.php |
| KnowledgeIndexRecords | app/Filament/Resources/KnowledgeIndexRecords/*Resource.php |
| KnowledgeIndexUnits | app/Filament/Resources/KnowledgeIndexUnits/*Resource.php |
| KnowledgeEmbeddings | app/Filament/Resources/KnowledgeEmbeddings/*Resource.php |

### Agentic Flow

Agents, Experts, assignments, execution state, delegation, memory, and approvals.

| Resource | Filament class location |
| --- | --- |
| AgentDescriptors | app/Filament/Resources/AgentDescriptors/*Resource.php |
| ExpertDescriptors | app/Filament/Resources/ExpertDescriptors/*Resource.php |
| AgentRuntimePolicies | app/Filament/Resources/AgentRuntimePolicies/*Resource.php |
| AgentAssignments | app/Filament/Resources/AgentAssignments/*Resource.php |
| AgentExecutions | app/Filament/Resources/AgentExecutions/*Resource.php |
| AgentExecutionSteps | app/Filament/Resources/AgentExecutionSteps/*Resource.php |
| AgentExecutionEventRecords | app/Filament/Resources/AgentExecutionEventRecords/*Resource.php |
| AgentDelegations | app/Filament/Resources/AgentDelegations/*Resource.php |
| AgentDecisions | app/Filament/Resources/AgentDecisions/*Resource.php |
| AgentEpisodicMemories | app/Filament/Resources/AgentEpisodicMemories/*Resource.php |
| AgentSemanticMemories | app/Filament/Resources/AgentSemanticMemories/*Resource.php |
| AgentSemanticMemoryVersions | app/Filament/Resources/AgentSemanticMemoryVersions/*Resource.php |
| ApprovalPolicies | app/Filament/Resources/ApprovalPolicies/*Resource.php |
| ApprovalRequests | app/Filament/Resources/ApprovalRequests/*Resource.php |
| ApprovalDecisions | app/Filament/Resources/ApprovalDecisions/*Resource.php |

### Workflow Flow

Persisted workflow definitions, stages, executions, jobs, and dependencies.

| Resource | Filament class location |
| --- | --- |
| Workflows | app/Filament/Resources/Workflows/*Resource.php |
| WorkflowVersions | app/Filament/Resources/WorkflowVersions/*Resource.php |
| WorkflowStages | app/Filament/Resources/WorkflowStages/*Resource.php |
| WorkflowExecutions | app/Filament/Resources/WorkflowExecutions/*Resource.php |
| Jobs | app/Filament/Resources/Jobs/*Resource.php |
| Executions | app/Filament/Resources/Executions/*Resource.php |
| Dependencies | app/Filament/Resources/Dependencies/*Resource.php |

### Marketing

Marketing strategy, content, audiences, channels, assets, media generation, and publishing state.

| Resource | Filament class location |
| --- | --- |
| MarketingStrategies | app/Filament/Resources/MarketingStrategies/*Resource.php |
| Campaigns | app/Filament/Resources/Campaigns/*Resource.php |
| ContentSeries | app/Filament/Resources/ContentSeries/*Resource.php |
| ContentItems | app/Filament/Resources/ContentItems/*Resource.php |
| Scripts | app/Filament/Resources/Scripts/*Resource.php |
| Audiences | app/Filament/Resources/Audiences/*Resource.php |
| Channels | app/Filament/Resources/Channels/*Resource.php |
| SocialAccounts | app/Filament/Resources/SocialAccounts/*Resource.php |
| Publications | app/Filament/Resources/Publications/*Resource.php |
| PublicationSchedules | app/Filament/Resources/PublicationSchedules/*Resource.php |
| PublishingJobs | app/Filament/Resources/PublishingJobs/*Resource.php |
| PublicationResults | app/Filament/Resources/PublicationResults/*Resource.php |
| Assets | app/Filament/Resources/Assets/*Resource.php |
| AssetVersions | app/Filament/Resources/AssetVersions/*Resource.php |
| MediaMetadata | app/Filament/Resources/MediaMetadata/*Resource.php |
| RenderRequests | app/Filament/Resources/RenderRequests/*Resource.php |
| RenderJobs | app/Filament/Resources/RenderJobs/*Resource.php |
| RenderOutputs | app/Filament/Resources/RenderOutputs/*Resource.php |
| Transformations | app/Filament/Resources/Transformations/*Resource.php |
| GenerationRequests | app/Filament/Resources/GenerationRequests/*Resource.php |
| GenerationJobs | app/Filament/Resources/GenerationJobs/*Resource.php |

### Finance

Financial accounts, periods, transactions, statements, customers, partners, invoices, and financial reporting.

| Resource | Filament class location |
| --- | --- |
| FinancialAccounts | app/Filament/Resources/FinancialAccounts/*Resource.php |
| FinancialPeriods | app/Filament/Resources/FinancialPeriods/*Resource.php |
| TransactionCategories | app/Filament/Resources/TransactionCategories/*Resource.php |
| Transactions | app/Filament/Resources/Transactions/*Resource.php |
| Revenues | app/Filament/Resources/Revenues/*Resource.php |
| Expenses | app/Filament/Resources/Expenses/*Resource.php |
| Budgets | app/Filament/Resources/Budgets/*Resource.php |
| Statements | app/Filament/Resources/Statements/*Resource.php |
| StatementEntries | app/Filament/Resources/StatementEntries/*Resource.php |
| Customers | app/Filament/Resources/Customers/*Resource.php |
| Partners | app/Filament/Resources/Partners/*Resource.php |
| Invoices | app/Filament/Resources/Invoices/*Resource.php |
| FinancialReports | app/Filament/Resources/FinancialReports/*Resource.php |
| BusinessHealthResults | app/Filament/Resources/BusinessHealthResults/*Resource.php |

### Reporting & Analytics

Cross-domain reports, snapshots, and report metric values.

| Resource | Filament class location |
| --- | --- |
| Reports | app/Filament/Resources/Reports/*Resource.php |
| ReportSnapshots | app/Filament/Resources/ReportSnapshots/*Resource.php |
| ReportMetricValues | app/Filament/Resources/ReportMetricValues/*Resource.php |

### Integrations & External Systems

External resources, integrations, integration execution/results, and command webhook deliveries.

| Resource | Filament class location |
| --- | --- |
| IntegrationConnections | app/Filament/Resources/IntegrationConnections/*Resource.php |
| IntegrationJobs | app/Filament/Resources/IntegrationJobs/*Resource.php |
| IntegrationResults | app/Filament/Resources/IntegrationResults/*Resource.php |
| ExternalResources | app/Filament/Resources/ExternalResources/*Resource.php |
| CommandWebhookDeliveries | app/Filament/Resources/CommandWebhookDeliveries/*Resource.php |

### Task and WorkItem domain contract

`Task` and `WorkItem` are distinct peer concepts within the Work domain. They are not parent/child variants of one abstraction and there is no direct `Task -> WorkItem` relationship.

- **Task** is the hierarchical planning/work-management entity. It belongs to an Enterprise, may belong to a Project, supports parent/child Task hierarchy, and owns Task-specific priority and due-date semantics.
- **WorkItem** is a lightweight operational work subject. It belongs to an Enterprise, may belong to a Project, and can have a direct Workflow association. It intentionally does not carry Task hierarchy, priority, or due-date semantics.
- A Project may contain Tasks and WorkItems independently. Their shared Enterprise/Project scope does not make one a specialization of the other.
- A Workflow may carry `task_id`, `work_item_id`, both, or neither. These fields provide contextual references rather than defining an inheritance relationship. Existing execution tests explicitly preserve both contexts together.
- An Execution snapshots both optional Task and WorkItem references and their historical names when present, preserving the distinction in runtime history.
- WorkItem-specific governed operations (`work.item.create` and `work.item.update`) operate on WorkItems through the normal Capability boundary. Task remains a separate work-management model with its own policies and administration surface.

This distinction is based on the verified repository schema, model relationships, workflow/execution persistence, services, policies, Filament resources, MCP capabilities, and tests. No schema migration or model merge is required.

The resulting Enterprise Context navigation sequence may therefore present `Task` and `WorkItem` as adjacent peer resources after `Project` and `Milestone`; this ordering reflects their shared work-domain level, not a parent/child relationship.

### Resource ordering rules

Within each navigation group, the resource tables above are the canonical logical order. The order is based on domain dependency and operational flow rather than alphabetical class names. Each resource uses an explicit numeric `navigationSort` value in increments of 10 within its group, starting at 10.

- Foundational scope/context records precede dependent records.
- Definitions precede runtime and execution records.
- Agent resources follow **Agent → Expert → runtime policy → assignment → execution → delegation/decision → memory → approval**.
- Workflow resources follow **definition → version → stage → dependency → execution**.
- Knowledge resources follow **context/source → document/item → version/specification/reference → index → embedding**.
- Marketing follows **strategy → campaign/content planning → audience/channel/account → content/script → asset/media/generation/rendering → publication/publishing/result**.
- Finance follows **financial context → counterparties/activity → planning/statements/invoices → reporting/health**.
- Integrations follow **connection → external resource → job → result → delivery**.
- New resources must be assigned an intentional position and sort value. They must not inherit alphabetical or incidental registration order.

The numeric sort values are scoped to their navigation group; the fixed top-level group order remains authoritative.

### Navigation rules

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