# Filament Resource Presentation Audit

This inventory is the maintained presentation contract for the current Filament Resource surface. It covers all resources under `app/Filament/Resources/**`, records the intentional navigation label and effective list-table columns, and identifies whether the table is defined directly on the Resource or through a dedicated `Tables/*Table.php` class.

## Conventions

- Navigation labels are explicit, human-readable UI labels and must not expose PascalCase class names.
- Singular/plural terminology is intentional and should follow the domain resource name.
- List tables should identify records using meaningful domain fields, relationships, statuses, dates, amounts, or other operationally useful values.
- Internal IDs may be retained as secondary identifiers but must not be the sole displayed field without a documented exception.
- Secrets, credentials, raw payloads, tokens, and other sensitive implementation details must not be exposed merely for completeness.
- Relationship columns should use meaningful related-record fields rather than raw foreign-key IDs when practical.
- Search and sort should be enabled where useful and safe for the displayed field.

## Inventory

| Group | Sort | Resource | Model | Navigation label | Effective list-table columns | Table definition |
| --- | ---: | --- | --- | --- | --- | --- |
| Agentic Flow | 10 | `AgentDescriptors` | `AgentDescriptor` | Agents | slug, runtime_class, enabled, created_at | `Filament/Resources/AgentDescriptors/AgentDescriptorResource.php` |
| Agentic Flow | 20 | `ExpertDescriptors` | `ExpertDescriptor` | Experts | slug, runtime_class, enabled | `Filament/Resources/ExpertDescriptors/ExpertDescriptorResource.php` |
| Agentic Flow | 30 | `AgentRuntimePolicies` | `AgentRuntimePolicy` | Agent Runtime Policies | environment, organization_id, enterprise_id, agent_descriptor_id, expert_descriptor_id, enabled, provider, max_steps, max_retries, timeout_seconds | `Filament/Resources/AgentRuntimePolicies/AgentRuntimePolicyResource.php` |
| Agentic Flow | 40 | `AgentAssignments` | `AgentAssignment` | Agent Assignments | agentDescriptor.slug, organization.name, enterprise.name, enabled | `Filament/Resources/AgentAssignments/AgentAssignmentResource.php` |
| Agentic Flow | 50 | `AgentExecutions` | `AgentExecution` | Agent Executions | agent_slug, organization_name, enterprise_name, actor_name, status, requested_at, completed_at | `Filament/Resources/AgentExecutions/AgentExecutionResource.php` |
| Agentic Flow | 60 | `AgentExecutionSteps` | `AgentExecutionStep` | Agent Execution Steps | execution.id, sequence, workflowStage.name, type, status, completed_at, failure_reason | `Filament/Resources/AgentExecutionSteps/AgentExecutionStepResource.php` |
| Agentic Flow | 70 | `AgentExecutionEventRecords` | `AgentExecutionEventRecord` | Agent Execution Event Records | execution.id, event_type, visibility, version, correlation_id, occurred_at | `Filament/Resources/AgentExecutionEventRecords/AgentExecutionEventRecordResource.php` |
| Agentic Flow | 80 | `AgentDelegations` | `AgentDelegation` | Agent Delegations | source_agent_slug, target_agent_slug, organization_name, enterprise_name, actor_name, capability, status, attempts, requested_at, started_at, completed_at, failure_reason | `Filament/Resources/AgentDelegations/AgentDelegationResource.php` |
| Agentic Flow | 90 | `AgentDecisions` | `AgentDecision` | Agent Decisions | title, agent_slug, organization_name, enterprise_name, actor_name, decided_at | `Filament/Resources/AgentDecisions/AgentDecisionResource.php` |
| Agentic Flow | 100 | `AgentEpisodicMemories` | `AgentEpisodicMemory` | Agent Episodic Memories | agentDescriptor.slug, enterprise.name, topic, objective, outcome, occurred_at | `Filament/Resources/AgentEpisodicMemories/AgentEpisodicMemoryResource.php` |
| Agentic Flow | 110 | `AgentSemanticMemories` | `AgentSemanticMemory` | Agent Semantic Memories | agentDescriptor.slug, enterprise.name, statement, confidence, status, updated_at | `Filament/Resources/AgentSemanticMemories/AgentSemanticMemoryResource.php` |
| Agentic Flow | 120 | `AgentSemanticMemoryVersions` | `AgentSemanticMemoryVersion` | Agent Semantic Memory Versions | memory.statement, status, change_type, changedBy.name, recorded_at | `Filament/Resources/AgentSemanticMemoryVersions/AgentSemanticMemoryVersionResource.php` |
| Agentic Flow | 130 | `ApprovalPolicies` | `ApprovalPolicy` | Approval Policies | policy_key, capability, enabled, expires_in_minutes, allow_self_approval | `Filament/Resources/ApprovalPolicies/ApprovalPolicyResource.php` |
| Agentic Flow | 140 | `ApprovalRequests` | `ApprovalRequest` | Approval Requests | capability, agent_slug, organization_name, enterprise_name, actor_name, status, requested_at, expires_at | `Filament/Resources/ApprovalRequests/ApprovalRequestResource.php` |
| Agentic Flow | 150 | `ApprovalDecisions` | `ApprovalDecision` | Approval Decisions | approvalRequest.capability, stage, decision, actor_name, decided_at, reason | `Filament/Resources/ApprovalDecisions/ApprovalDecisionResource.php` |
| Enterprise Context | 10 | `EnterpriseContexts` | `EnterpriseContext` | Enterprise Contexts | enterprise.name, industry, business_model, target_market, geography, updated_at | `Filament/Resources/EnterpriseContexts/EnterpriseContextResource.php` |
| Enterprise Context | 20 | `Visions` | `Vision` | Visions | enterprise.name, version, status, effective_from | `Filament/Resources/Visions/VisionResource.php` |
| Enterprise Context | 30 | `Missions` | `Mission` | Missions | enterprise.name, version, status, effective_from | `Filament/Resources/Missions/MissionResource.php` |
| Enterprise Context | 40 | `Goals` | `Goal` | Goals | name, enterprise.name, status | `Filament/Resources/Goals/GoalResource.php` |
| Enterprise Context | 50 | `Objectives` | `Objective` | Objectives | name, enterprise.name, goal.name, kpi.name | `Filament/Resources/Objectives/ObjectiveResource.php` |
| Enterprise Context | 60 | `Kpis` | `Kpi` | KPIs | name, enterprise.name, status | `Filament/Resources/Kpis/KpiResource.php` |
| Enterprise Context | 70 | `MetricDefinitions` | `MetricDefinition` | Metric Definitions | key, name, unit, status | `Filament/Resources/MetricDefinitions/MetricDefinitionResource.php` |
| Enterprise Context | 80 | `Strategies` | `Strategy` | Strategies | name, objective.name | `Filament/Resources/Strategies/StrategyResource.php` |
| Enterprise Context | 90 | `Plans` | `Plan` | Plans | name, strategy.name | `Filament/Resources/Plans/PlanResource.php` |
| Enterprise Context | 100 | `Initiatives` | `Initiative` | Initiatives | name, plan.name | `Filament/Resources/Initiatives/InitiativeResource.php` |
| Enterprise Context | 110 | `Projects` | `Project` | Projects | name, enterprise.name, status | `Filament/Resources/Projects/ProjectResource.php` |
| Enterprise Context | 120 | `Milestones` | `Milestone` | Milestones | name, project.name, status | `Filament/Resources/Milestones/MilestoneResource.php` |
| Enterprise Context | 130 | `Tasks` | `Task` | Tasks | name, project.name, status, priority | `Filament/Resources/Tasks/TaskResource.php` |
| Enterprise Context | 140 | `WorkItems` | `WorkItem` | Work Items | name, project.name, status | `Filament/Resources/WorkItems/WorkItemResource.php` |
| Enterprise Context | 150 | `Decisions` | `Decision` | Decisions | title, type, enterprise.name, actor_name, decided_at | `Filament/Resources/Decisions/DecisionResource.php` |
| Enterprise Context | 160 | `EnterpriseDecisions` | `EnterpriseDecision` | Enterprise Decisions | title, enterprise.name, actor_name, decided_at | `Filament/Resources/EnterpriseDecisions/EnterpriseDecisionResource.php` |
| Enterprise Context | 170 | `Competitors` | `Competitor` | Competitors | name, enterprise.name, version, status | `Filament/Resources/Competitors/CompetitorResource.php` |
| Enterprise Context | 180 | `Products` | `Product` | Products | name, enterprise.name, status | `Filament/Resources/Products/ProductResource.php` |
| Finance | 10 | `FinancialAccounts` | `FinancialAccount` | Financial Accounts | enterprise.name, name, type, status, currency | `Filament/Resources/FinancialAccounts/FinancialAccountResource.php` |
| Finance | 20 | `FinancialPeriods` | `FinancialPeriod` | Financial Periods | enterprise_id, name, period_start, period_end, status | `Filament/Resources/FinancialPeriods/FinancialPeriodResource.php` |
| Finance | 30 | `TransactionCategories` | `TransactionCategory` | Transaction Categories | enterprise_id, name | `Filament/Resources/TransactionCategories/TransactionCategoryResource.php` |
| Finance | 40 | `Customers` | `Customer` | Customers | name, enterprise.name, status | `Filament/Resources/Customers/CustomerResource.php` |
| Finance | 50 | `Partners` | `Partner` | Partners | name, enterprise.name, status | `Filament/Resources/Partners/PartnerResource.php` |
| Finance | 60 | `Revenues` | `Revenue` | Revenues | enterprise_id, financial_account_id, transaction_id, financial_period_id, amount, currency, revenue_date, source, reference, description | `Filament/Resources/Revenues/RevenueResource.php` |
| Finance | 70 | `Expenses` | `Expense` | Expenses | enterprise_id, financial_account_id, transaction_id, transaction_category_id, financial_period_id, amount, currency, expense_date, source, reference, description | `Filament/Resources/Expenses/ExpenseResource.php` |
| Finance | 80 | `Transactions` | `Transaction` | Transactions | enterprise_id, financial_account_id, transaction_category_id, financial_period_id, amount, transaction_date, description, reference | `Filament/Resources/Transactions/TransactionResource.php` |
| Finance | 90 | `Budgets` | `Budget` | Budgets | enterprise_id, financial_period_id, financial_account_id, transaction_category_id, name, planned_amount, currency, description | `Filament/Resources/Budgets/BudgetResource.php` |
| Finance | 100 | `Statements` | `Statement` | Statements | organization_id, enterprise_id, financial_account_id, source, source_reference, statement_date, period_start, period_end, metadata | `Filament/Resources/Statements/StatementResource.php` |
| Finance | 110 | `StatementEntries` | `StatementEntry` | Statement Entries | organization_id, enterprise_id, statement_id, financial_account_id, transaction_id, source, source_reference, amount, entry_date, description, reference | `Filament/Resources/StatementEntries/StatementEntryResource.php` |
| Finance | 120 | `Invoices` | `Invoice` | Invoices | enterprise_id, customer_id, partner_id, invoice_number, issue_date, due_date, total, currency, status, counterparty_name_snapshot, counterparty_email_snapshot | `Filament/Resources/Invoices/InvoiceResource.php` |
| Finance | 130 | `FinancialReports` | `FinancialReport` | Financial Reports | enterprise_id, financial_period_id, financial_account_id, transaction_category_id, currency, generated_at | `Filament/Resources/FinancialReports/FinancialReportResource.php` |
| Finance | 140 | `BusinessHealthResults` | `BusinessHealthResult` | Business Health Results | enterprise_id, financial_report_id, health_status, evaluated_at | `Filament/Resources/BusinessHealthResults/BusinessHealthResultResource.php` |
| Integrations & External Systems | 10 | `IntegrationConnections` | `IntegrationConnection` | Integration Connections | organization.name, enterprise.name, provider, external_account_id, credential_reference, status | `Filament/Resources/IntegrationConnections/IntegrationConnectionResource.php` |
| Integrations & External Systems | 20 | `ExternalResources` | `ExternalResource` | External Resources | organization.name, enterprise.name, contentItem.title, asset.name, agentExecution.id, provider, resource_type, external_id | `Filament/Resources/ExternalResources/ExternalResourceResource.php` |
| Integrations & External Systems | 30 | `IntegrationJobs` | `IntegrationJob` | Integration Jobs | connection.id, organization.name, enterprise.name, contentItem.title, asset.name, provider, operation, idempotency_key | `Filament/Resources/IntegrationJobs/IntegrationJobResource.php` |
| Integrations & External Systems | 40 | `IntegrationResults` | `IntegrationResult` | Integration Results | provider, operation, external_result_id, status, processing_status, occurred_at, processed_at, failure_code | `Filament/Resources/IntegrationResults/IntegrationResultResource.php` |
| Integrations & External Systems | 50 | `CommandWebhookDeliveries` | `CommandWebhookDelivery` | Command Webhook Deliveries | capability, status, failure_code, correlation_id, processed_at | `Filament/Resources/CommandWebhookDeliveries/CommandWebhookDeliveryResource.php` |
| Knowledge Management | 10 | `KnowledgeContexts` | `KnowledgeContext` | Knowledge Contexts | name, type | `Filament/Resources/KnowledgeContexts/Tables/KnowledgeContextsTable.php` |
| Knowledge Management | 20 | `KnowledgeSources` | `KnowledgeSource` | Knowledge Sources | name, type | `Filament/Resources/KnowledgeSources/Tables/KnowledgeSourcesTable.php` |
| Knowledge Management | 30 | `KnowledgeDocuments` | `KnowledgeDocument` | Knowledge Documents | title, status | `Filament/Resources/KnowledgeDocuments/Tables/KnowledgeDocumentsTable.php` |
| Knowledge Management | 40 | `KnowledgeItems` | `KnowledgeItem` | Knowledge Items | title, type | `Filament/Resources/KnowledgeItems/Tables/KnowledgeItemsTable.php` |
| Knowledge Management | 50 | `KnowledgeVersions` | `KnowledgeVersion` | Knowledge Versions | item.title, version, recorded_at | `Filament/Resources/KnowledgeVersions/Tables/KnowledgeVersionsTable.php` |
| Knowledge Management | 60 | `KnowledgeSpecifications` | `KnowledgeSpecification` | Knowledge Specifications | name, version | `Filament/Resources/KnowledgeSpecifications/Tables/KnowledgeSpecificationsTable.php` |
| Knowledge Management | 70 | `KnowledgeReferences` | `KnowledgeReference` | Knowledge References | label, type | `Filament/Resources/KnowledgeReferences/Tables/KnowledgeReferencesTable.php` |
| Knowledge Management | 80 | `KnowledgeIndexRecords` | `KnowledgeIndexRecord` | Knowledge Index Records | source.name, document.title, item.title, unit_key, representation_key, status, provider, indexed_at | `Filament/Resources/KnowledgeIndexRecords/KnowledgeIndexRecordResource.php` |
| Knowledge Management | 90 | `KnowledgeIndexUnits` | `KnowledgeIndexUnit` | Knowledge Index Units | record.unit_key, item.title, version.version, ordinal, heading_path | `Filament/Resources/KnowledgeIndexUnits/KnowledgeIndexUnitResource.php` |
| Knowledge Management | 100 | `KnowledgeEmbeddings` | `KnowledgeEmbedding` | Knowledge Embeddings | unit.unit_key, version.version, embedding_version, content_hash | `Filament/Resources/KnowledgeEmbeddings/KnowledgeEmbeddingResource.php` |
| Marketing | 10 | `MarketingStrategies` | `MarketingStrategy` | Marketing Strategies | name, enterprise.name, status | `Filament/Resources/MarketingStrategies/MarketingStrategyResource.php` |
| Marketing | 20 | `Campaigns` | `Campaign` | Campaigns | name, enterprise.name, marketingStrategy.name, status | `Filament/Resources/Campaigns/CampaignResource.php` |
| Marketing | 30 | `ContentSeries` | `ContentSeries` | Content Series | name, campaign.name, status | `Filament/Resources/ContentSeries/ContentSeriesResource.php` |
| Marketing | 40 | `Audiences` | `Audience` | Audiences | name, enterprise.name, status | `Filament/Resources/Audiences/AudienceResource.php` |
| Marketing | 50 | `Channels` | `Channel` | Channels | name, type, enterprise.name, status | `Filament/Resources/Channels/ChannelResource.php` |
| Marketing | 60 | `SocialAccounts` | `SocialAccount` | Social Accounts | enterprise.name, channel.name, provider, name, external_id, status | `Filament/Resources/SocialAccounts/SocialAccountResource.php` |
| Marketing | 70 | `ContentItems` | `ContentItem` | Content Items | title, enterprise.name, campaign.name, contentSeries.name, status | `Filament/Resources/ContentItems/ContentItemResource.php` |
| Marketing | 80 | `Scripts` | `Script` | Scripts | title, contentItem.title | `Filament/Resources/Scripts/ScriptResource.php` |
| Marketing | 90 | `Assets` | `Asset` | Assets | enterprise.name, contentItem.title, name, type, status | `Filament/Resources/Assets/AssetResource.php` |
| Marketing | 100 | `AssetVersions` | `AssetVersion` | Asset Versions | asset.name, version, disk, path, mime_type, size, checksum, external_reference | `Filament/Resources/AssetVersions/AssetVersionResource.php` |
| Marketing | 110 | `MediaMetadata` | `MediaMetadata` | Media Metadata | assetVersion.version, width, height, duration_seconds, codec, frame_rate | `Filament/Resources/MediaMetadata/MediaMetadataResource.php` |
| Marketing | 120 | `GenerationRequests` | `GenerationRequest` | Generation Requests | enterprise.name, contentItem.title, asset.name, type, status, correlation_id, external_request_id, failure_code | `Filament/Resources/GenerationRequests/GenerationRequestResource.php` |
| Marketing | 130 | `GenerationJobs` | `GenerationJob` | Generation Jobs | request.id, workflowJob.id, execution.id, external_job_id, status, failure_reason | `Filament/Resources/GenerationJobs/GenerationJobResource.php` |
| Marketing | 140 | `RenderRequests` | `RenderRequest` | Render Requests | enterprise.name, contentItem.title, asset.name, sourceVersion.version, type, status, correlation_id, external_request_id | `Filament/Resources/RenderRequests/RenderRequestResource.php` |
| Marketing | 150 | `RenderJobs` | `RenderJob` | Render Jobs | request.id, workflowJob.id, execution.id, external_job_id, status, failure_reason | `Filament/Resources/RenderJobs/RenderJobResource.php` |
| Marketing | 160 | `RenderOutputs` | `RenderOutput` | Render Outputs | request.id, assetVersion.version, external_output_id, disk, path, mime_type, size, checksum | `Filament/Resources/RenderOutputs/RenderOutputResource.php` |
| Marketing | 170 | `Transformations` | `Transformation` | Transformations | asset.name, sourceVersion.version, outputVersion.version, type | `Filament/Resources/Transformations/TransformationResource.php` |
| Marketing | 180 | `Publications` | `Publication` | Publications | enterprise.name, contentItem.title, channel.name, socialAccount.name, approvalRequest.id, status, external_id, external_url | `Filament/Resources/Publications/PublicationResource.php` |
| Marketing | 190 | `PublicationSchedules` | `PublicationSchedule` | Publication Schedules | enterprise.name, publication.id, scheduled_at, status | `Filament/Resources/PublicationSchedules/PublicationScheduleResource.php` |
| Marketing | 200 | `PublishingJobs` | `PublishingJob` | Publishing Jobs | enterprise.name, publication.id, idempotency_key, attempts, status, failure_code, failure_reason, started_at | `Filament/Resources/PublishingJobs/PublishingJobResource.php` |
| Marketing | 210 | `PublicationResults` | `PublicationResult` | Publication Results | enterprise.name, publication.id, publishingJob.id, provider, provider_status, external_id, external_url, correlation_id | `Filament/Resources/PublicationResults/PublicationResultResource.php` |
| Organization & Enterprise Scope | 10 | `Organizations` | `Organization` | Organization | name, slug | `Filament/Resources/Organizations/OrganizationResource.php` |
| Organization & Enterprise Scope | 20 | `Users` | `User` | Users | name, email, created_at | `Filament/Resources/Users/UserResource.php` |
| Organization & Enterprise Scope | 30 | `Memberships` | `Membership` | Memberships | user.name, organization.name, role | `Filament/Resources/Memberships/MembershipResource.php` |
| Organization & Enterprise Scope | 40 | `Enterprises` | `Enterprise` | Enterprises | name, organization.name, status | `Filament/Resources/Enterprises/EnterpriseResource.php` |
| Organization & Enterprise Scope | 50 | `Assignments` | `Assignment` | Assignments | assignable.name, assignable_type, enterprise.name, user.name, agentAssignment.agentDescriptor.slug | `Filament/Resources/Assignments/AssignmentResource.php` |
| Reporting & Analytics | 10 | `Reports` | `Report` | Reports | enterprise.name, report_type, status, period_start, period_end, generated_at, methodology_version | `Filament/Resources/Reports/ReportResource.php` |
| Reporting & Analytics | 20 | `ReportSnapshots` | `ReportSnapshot` | Report Snapshots | report.report_type, captured_at, period_start, period_end, methodology_version | `Filament/Resources/ReportSnapshots/ReportSnapshotResource.php` |
| Reporting & Analytics | 30 | `ReportMetricValues` | `ReportMetricValue` | Report Metric Values | report.report_type, metricDefinition.name, value, unit | `Filament/Resources/ReportMetricValues/ReportMetricValueResource.php` |
| Workflow Flow | 10 | `Workflows` | `Workflow` | Workflows | name, canonical_key, enterprise.name, publishedVersion.version, status, created_at | `Filament/Resources/Workflows/WorkflowResource.php` |
| Workflow Flow | 20 | `WorkflowVersions` | `WorkflowVersion` | Workflow Versions | workflow.name, enterprise.name, version, status, created_at, published_at, retired_at | `Filament/Resources/WorkflowVersions/WorkflowVersionResource.php` |
| Workflow Flow | 30 | `WorkflowStages` | `WorkflowStage` | Workflow Stages | workflow.name, key, name, sequence, repeatable | `Filament/Resources/WorkflowStages/WorkflowStageResource.php` |
| Workflow Flow | 40 | `Dependencies` | `Dependency` | Dependencies | predecessor.name, successor.name, type, project.name, enterprise.name | `Filament/Resources/Dependencies/DependencyResource.php` |
| Workflow Flow | 50 | `WorkflowExecutions` | `WorkflowExecution` | Workflow Executions | workflow.name, workflowVersion.version, currentStage.name, status, started_at, completed_at, failure_reason | `Filament/Resources/WorkflowExecutions/WorkflowExecutionResource.php` |
| Workflow Flow | 60 | `Executions` | `Execution` | Executions | job.name, job.workflow.name, enterprise_name, project_name, task_name, work_item_name, status, started_at, completed_at, failure_reason | `Filament/Resources/Executions/ExecutionResource.php` |
| Workflow Flow | 70 | `Jobs` | `Job` | Jobs | name, workflow.name, workflow.enterprise.name, status, attempts, started_at, completed_at | `Filament/Resources/Jobs/JobResource.php` |
