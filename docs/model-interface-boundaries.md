# Model Interface Boundaries

CR8OR exposes persistent domain records through Filament and MCP. Those surfaces must represent domain semantics, not turn every Eloquent model into unrestricted CRUD.

## A. Human CRUD

Business intent, configuration, knowledge, planning, or maintainable business state. Authorized humans may create/edit/delete where the Resource and policy permit it. Existing model invariants may still make selected fields immutable.

**Models:** Organization, Enterprise, Membership, EnterpriseContext, Customer, Partner, Product, Plan, Goal, KPI, Objective, Strategy, Initiative, Project, Task, WorkItem, Assignment, Dependency, Milestone, KnowledgeContext, KnowledgeDocument, KnowledgeItem, KnowledgeReference, KnowledgeSource, KnowledgeSpecification, Script, Asset, Audience, Channel, FinancialAccount, FinancialPeriod, Budget, Invoice, Revenue, Expense, TransactionCategory, Statement, StatementEntry, Transaction.

**Version records:** KnowledgeVersion is create-only. It preserves a new version rather than providing unrestricted update/delete semantics.

## B. Controlled human/domain actions

Consequential state changes use an application/domain path rather than arbitrary field mutation.

- AgentDescriptor / ExpertDescriptor: registry controls only. Runtime identity, description, responsibilities, capabilities, required context, and methodology remain authoritative in PHP and are displayed read-only.
- AgentAssignment / AgentPermission: governed assignment and permission administration.
- ApprovalRequest: approve/reject through `ApprovalRequestService` and policy authorization.
- SocialAccount: connection/account administration; raw credentials/tokens are never exposed.
- IntegrationConnection: connection configuration and state, not operational history.
- MarketingStrategy, Campaign, ContentSeries: descriptive CRUD plus lifecycle transitions through domain services.
- ContentItem: descriptive editing while draft/in-review; review/approval/publication lifecycle through `ContentItemService`.
- Publication: publication lifecycle/execution boundary.
- Decision: historical context is protected by model invariants; mutable explanatory content remains controlled human data.

## C. Read-only operational, derived, and historical records

Execution, provenance, result, generated-observation, or historical records are exposed for inspection and do not receive unrestricted update/delete CRUD.

**Models:** AgentDecision, AgentDelegation, AgentExecution, AssetVersion, BusinessHealthResult, Execution, ExternalResource, FinancialReport, GenerationJob, GenerationRequest, IntegrationJob, Job, MediaMetadata, PublicationResult, PublicationSchedule, PublishingJob, RenderJob, RenderOutput, RenderRequest, Transformation, Workflow.

ApprovalRequest is a controlled exception: it is historically preserved, but `approve` and `reject` are explicit domain actions.

## Filament rules

1. Read-only Resources expose inspection pages only.
2. Controlled Resources expose domain actions for consequential transitions.
3. Lifecycle status fields are not freely editable on edit forms when a model already provides a transition method.
4. Historical/system-managed/provenance fields are not exposed as editable form inputs.
5. Runtime Agent/Expert metadata remains code-authoritative.
6. UI visibility is never an authorization boundary.

## MCP rules

MCP Tools represent business operations through the governed Capability → Operation boundary, not database-table CRUD. Discovery/get/list actions are appropriate for all classes. Create/update actions exist only where the business record is legitimately maintainable. Lifecycle actions use existing domain/application transitions. Approval actions use the approval service. Social-account actions do not expose raw credentials. Operational, provenance, result, and historical records do not receive generic update/delete actions.

## Authorization and integrity

Every interface preserves organization isolation, enterprise scope, server-side policy authorization, domain/application authorization, approval requirements, lifecycle invariants, and historical/provenance protections.

## Audit outcome

The repository already contains substantial boundary enforcement. This classification formalizes the current interface contract and is the reference for future Filament Resources and MCP Tools. A new model or Resource must be classified before generic CRUD is exposed.
## Finance Agent Operation boundary

`FinancialReport` remains a read-only derived/historical record and is not exposed through generic Agent CRUD. The governed `generate-financial-report` MCP Tool is the current Agent-facing Finance operation because it maps to the existing `FinancialReportingService` and `FinancialReportPolicy::createForEnterprise` boundary. Financial accounts, transactions, statements, invoices, expenses, revenue, financial periods, budgets and transaction categories retain their existing policy/model boundaries and are not promoted to arbitrary Agent mutation without a dedicated application/domain action.
