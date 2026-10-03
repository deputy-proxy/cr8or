# Filament Resource Presentation Inventory

This inventory is generated from the current app/Filament/Resources surface and is the maintained presentation contract for resource labels, navigation placement, and list-table columns.

## Conventions

- Navigation, singular, and plural labels are explicit and human-readable.
- List tables expose operationally useful domain fields rather than merely database identifiers.
- Human-readable relationship attributes are preferred where safely available.
- Secrets, credentials, tokens, raw private payloads, vectors, and private provenance are excluded.
- Statuses use badges; dates use date/date-time formatting; numeric values use numeric formatting.
- Searchable and sortable behavior is used where operationally useful.

## Resource Inventory

| Resource | Model | Navigation label | Singular | Plural | Group | Sort | List columns |
|---|---|---|---|---|---|---:|---|
| AgentAssignments | AgentAssignment | Agent Assignments | Agent Assignment | Agent Assignments | Agentic Flow | 40 | agent_descriptor_id, organization_id, enterprise_id, enabled, agentDescriptor.slug, organization.name, enterprise.name, enabled |
| AgentDecisions | AgentDecision | Agent Decisions | Agent Decision | Agent Decisions | Agentic Flow | 90 | title, agent_slug, organization_name, enterprise_name, actor_name, decided_at |
| AgentDelegations | AgentDelegation | Agent Delegations | Agent Delegation | Agent Delegations | Agentic Flow | 80 | source_agent_slug, target_agent_slug, organization_name, enterprise_name, actor_name, capability, status, attempts, requested_at, started_at, completed_at, failure_reason |
| AgentDescriptors | AgentDescriptor | Agents | Agent | Agents | Agentic Flow | 10 | slug, runtime_class, enabled, runtime_name, runtime_description, runtime_instructions, runtime_responsibilities, runtime_experts, runtime_capabilities, runtime_required_context, slug, runtime_class, enabled, created_at |
| AgentEpisodicMemories | AgentEpisodicMemory | Agent Episodic Memories | Agent Episodic Memory | Agent Episodic Memories | Agentic Flow | 100 | agentDescriptor.slug, topic, objective, outcome, occurred_at |
| AgentExecutionEventRecords | AgentExecutionEventRecord | Agent Execution Event Records | Agent Execution Event Record | Agent Execution Event Records | Agentic Flow | 70 | event_type, agent_execution_id, actor_id, visibility, occurred_at, correlation_id |
| AgentExecutionSteps | AgentExecutionStep | Agent Execution Steps | Agent Execution Step | Agent Execution Steps | Agentic Flow | 60 | agent_execution_id, sequence, status, type, started_at, completed_at, failure_code |
| AgentExecutions | AgentExecution | Agent Executions | Agent Execution | Agent Executions | Agentic Flow | 50 | agent_slug, organization_name, enterprise_name, actor_name, status, requested_at, completed_at, view |
| AgentRuntimePolicies | AgentRuntimePolicy | Agent Runtime Policies | Agent Runtime Policie | Agent Runtime Policies | Agentic Flow | 30 | environment, organization_id, enterprise_id, agent_descriptor_id, expert_descriptor_id, enabled, provider, max_steps, max_retries, timeout_seconds |
| AgentSemanticMemories | AgentSemanticMemory | Agent Semantic Memories | Agent Semantic Memory | Agent Semantic Memories | Agentic Flow | 110 | agentDescriptor.slug, statement, confidence, status |
| AgentSemanticMemoryVersions | AgentSemanticMemoryVersion | Agent Semantic Memory Versions | Agent Semantic Memory Version | Agent Semantic Memory Versions | Agentic Flow | 120 | agent_semantic_memory_id, agentDescriptor.slug, statement, confidence, status, change_type, recorded_at |
| ApprovalDecisions | ApprovalDecision | Approval Decisions | Approval Decision | Approval Decisions | Agentic Flow | 150 | approval_request_id, stage, decision, actor_name, decided_at |
| ApprovalPolicies | ApprovalPolicy | Approval Policies | Approval Policy | Approval Policies | Agentic Flow | 130 | policy_key, capability, expires_in_minutes, allow_self_approval, enabled |
| ApprovalRequests | ApprovalRequest | Approval Requests | Approval Request | Approval Requests | Agentic Flow | 140 | capability, agent_slug, organization_name, enterprise_name, actor_name, status, requested_at, expires_at, approve, reject |
| AssetVersions | AssetVersion | Asset Versions | Asset Version | Asset Versions | Marketing | 100 | asset.name, version, disk, path, mime_type, size, checksum, external_reference |
| Assets | Asset | Assets | Asset | Assets | Marketing | 90 | enterprise_id, content_item_id, name, type, status, enterprise.name, contentItem.title, name, type, status |
| Assignments | Assignment | Assignments | Assignment | Assignments | Organization & Enterprise Scope | 50 | assignable.name, assignable_type, enterprise.name, user.name, agentAssignment.agentDescriptor.slug |
| Audiences | Audience | Audiences | Audience | Audiences | Marketing | 40 | enterprise_id, name |
| Budgets | Budget | Budgets | Budget | Budgets | Finance | 90 | enterprise_id, financial_period_id, financial_account_id, transaction_category_id, name, planned_amount, currency, description, enterprise_id, financial_period_id, financial_account_id, transaction_category_id, name, planned_amount, currency, description |
| BusinessHealthResults | BusinessHealthResult | Business Health Results | Business Health Result | Business Health Results | Finance | 140 | enterprise_id, financial_report_id, health_status, metrics, source_snapshot, evaluated_at, enterprise_id, financial_report_id, health_status, evaluated_at |
| Campaigns | Campaign | Campaigns | Campaign | Campaigns | Marketing | 20 | enterprise_id, marketing_strategy_id, name, description, status, name, enterprise.name, marketingStrategy.name, status, transition, status |
| Channels | Channel | Channels | Channel | Channels | Marketing | 50 | enterprise_id, name |
| CommandWebhookDeliveries | CommandWebhookDelivery | Command Webhook Deliveries | Command Webhook Delivery | Command Webhook Deliveries | Integrations & External Systems | 50 | capability, idempotency_key, status, failure_code, processed_at |
| Competitors | Competitor | Competitors | Competitor | Competitors | Enterprise Context | 170 | enterprise_id, name, website, positioning, strengths, weaknesses, name, enterprise.name, version, status |
| ContentItems | ContentItem | Content Items | Content Item | Content Items | Marketing | 70 | enterprise_id, campaign_id, content_series_id, channel_id, audience_id, title, body, title, enterprise.name, campaign.name, contentSeries.name, status, submit_for_review, approve |
| ContentSeries | ContentSeries | Content Series | Content Series | Content Series | Marketing | 30 | campaign_id, name, description, status, name, campaign.name, status, transition, status |
| Customers | Customer | Customers | Customer | Customers | Finance | 40 | enterprise_id, name |
| Decisions | Decision | Decisions | Decision | Decisions | Enterprise Context | 150 | enterprise_id, type, actor_id, objective_id, strategy_id, plan_id, initiative_id, project_id, task_id, work_item_id, title, summary, rationale, decided_at, title, type, enterprise.name, actor_name, decided_at |
| Dependencies | Dependency | Dependencies | Dependencie | Dependencies | Workflow Flow | 40 | predecessor.name, successor.name, type, project.name, enterprise.name |
| EnterpriseContexts | EnterpriseContext | Enterprise Contexts | Enterprise Context | Enterprise Contexts | Enterprise Context | 10 | enterprise_id, enterprise.name |
| EnterpriseDecisions | EnterpriseDecision | Enterprise Decisions | Enterprise Decision | Enterprise Decisions | Enterprise Context | 160 | enterprise_id, title |
| Enterprises | Enterprise | Enterprises | Enterprise | Enterprises | Organization & Enterprise Scope | 40 | organization_id, name |
| Executions | Execution | Executions | Execution | Executions | Workflow Flow | 60 | job.name, job.workflow.name, enterprise_name, project_name, task_name, work_item_name, status, started_at, completed_at, failure_reason |
| Expenses | Expense | Expenses | Expense | Expenses | Finance | 70 | enterprise_id, financial_account_id, transaction_id, transaction_category_id, financial_period_id, amount, currency, expense_date, source, reference, description, enterprise_id, financial_account_id, transaction_id, transaction_category_id, financial_period_id, amount, currency, expense_date, source, reference, description |
| ExpertDescriptors | ExpertDescriptor | Experts | Expert | Experts | Agentic Flow | 20 | slug, runtime_class, enabled, runtime_name, runtime_description, runtime_responsibilities, runtime_capabilities, runtime_required_context, runtime_methodology, slug, runtime_class, enabled |
| ExternalResources | ExternalResource | External Resources | External Resource | External Resources | Integrations & External Systems | 20 | organization.name, enterprise.name, contentItem.title, asset.name, agentExecution.id, provider, resource_type, external_id |
| FinancialAccounts | FinancialAccount | Financial Accounts | Financial Account | Financial Accounts | Finance | 10 | enterprise_id, name, type, status, currency, enterprise.name, name, type, status, currency |
| FinancialPeriods | FinancialPeriod | Financial Periods | Financial Period | Financial Periods | Finance | 20 | enterprise_id, name, period_start, period_end, status, enterprise_id, name, period_start, period_end, status |
| FinancialReports | FinancialReport | Financial Reports | Financial Report | Financial Reports | Finance | 130 | enterprise_id, financial_period_id, financial_account_id, transaction_category_id, currency, metrics, source_snapshot, generated_at, enterprise_id, financial_period_id, financial_account_id, transaction_category_id, currency, generated_at |
| GenerationJobs | GenerationJob | Generation Jobs | Generation Job | Generation Jobs | Marketing | 130 | request.id, workflowJob.id, execution.id, external_job_id, status, failure_reason |
| GenerationRequests | GenerationRequest | Generation Requests | Generation Request | Generation Requests | Marketing | 120 | enterprise.name, contentItem.title, asset.name, type, status, correlation_id, external_request_id, failure_code |
| Goals | Goal | Goals | Goal | Goals | Enterprise Context | 40 | enterprise_id, name |
| Initiatives | Initiative | Initiatives | Initiative | Initiatives | Enterprise Context | 100 | plan_id, name, description, name, plan.name |
| IntegrationConnections | IntegrationConnection | Integration Connections | Integration Connection | Integration Connections | Integrations & External Systems | 10 | organization_id, enterprise_id, provider, external_account_id, credential_reference, status, organization.name, enterprise.name, provider, external_account_id, credential_reference, status |
| IntegrationJobs | IntegrationJob | Integration Jobs | Integration Job | Integration Jobs | Integrations & External Systems | 30 | connection.id, organization.name, enterprise.name, contentItem.title, asset.name, provider, operation, idempotency_key |
| IntegrationResults | IntegrationResult | Integration Results | Integration Result | Integration Results | Integrations & External Systems | 40 | integration_job_id, integration_connection_id, provider, operation, status, processing_status, occurred_at, processed_at |
| Invoices | Invoice | Invoices | Invoice | Invoices | Finance | 120 | enterprise_id, customer_id, partner_id, invoice_number, issue_date, due_date, total, currency, status, counterparty_name_snapshot, counterparty_email_snapshot, enterprise_id, customer_id, partner_id, invoice_number, issue_date, due_date, total, currency, status, counterparty_name_snapshot, counterparty_email_snapshot |
| Jobs | Job | Jobs | Job | Jobs | Workflow Flow | 70 | name, workflow.name, workflow.enterprise.name, status, attempts, started_at, completed_at |
| KnowledgeContexts | KnowledgeContext | Knowledge Contexts | Knowledge Context | Knowledge Contexts | Knowledge Management | 10 |  |
| KnowledgeDocuments | KnowledgeDocument | Knowledge Documents | Knowledge Document | Knowledge Documents | Knowledge Management | 30 |  |
| KnowledgeEmbeddings | KnowledgeEmbedding | Knowledge Embeddings | Knowledge Embedding | Knowledge Embeddings | Knowledge Management | 100 | knowledge_index_unit_id, knowledge_index_record_id, knowledge_version_id, embedding_version, content_hash |
| KnowledgeIndexRecords | KnowledgeIndexRecord | Knowledge Index Records | Knowledge Index Record | Knowledge Index Records | Knowledge Management | 80 | knowledge_source_id, knowledge_document_id, knowledge_item_id, knowledge_version_id, unit_key, representation_key, status, provider, indexed_at, invalidated_at |
| KnowledgeIndexUnits | KnowledgeIndexUnit | Knowledge Index Units | Knowledge Index Unit | Knowledge Index Units | Knowledge Management | 90 | knowledge_index_record_id, knowledge_item_id, knowledge_version_id, unit_key, ordinal, content, heading_path |
| KnowledgeItems | KnowledgeItem | Knowledge Items | Knowledge Item | Knowledge Items | Knowledge Management | 40 |  |
| KnowledgeReferences | KnowledgeReference | Knowledge References | Knowledge Reference | Knowledge References | Knowledge Management | 70 |  |
| KnowledgeSources | KnowledgeSource | Knowledge Sources | Knowledge Source | Knowledge Sources | Knowledge Management | 20 |  |
| KnowledgeSpecifications | KnowledgeSpecification | Knowledge Specifications | Knowledge Specification | Knowledge Specifications | Knowledge Management | 60 |  |
| KnowledgeVersions | KnowledgeVersion | Knowledge Versions | Knowledge Version | Knowledge Versions | Knowledge Management | 50 |  |
| Kpis | Kpi | KPIs | KPI | KPIs | Enterprise Context | 60 | enterprise_id, name |
| MarketingStrategies | MarketingStrategy | Marketing Strategies | Marketing Strategy | Marketing Strategies | Marketing | 10 | enterprise_id, name, description, status, name, enterprise.name, status, transition, status |
| MediaMetadata | MediaMetadata | Media Metadata | Media Metadata | Media Metadata | Marketing | 110 | assetVersion.version, width, height, duration_seconds, codec, frame_rate |
| Memberships | Membership | Memberships | Membership | Memberships | Organization & Enterprise Scope | 30 | user_id, user.name |
| MetricDefinitions | MetricDefinition | Metric Definitions | Metric Definition | Metric Definitions | Enterprise Context | 70 | key, name, unit, status, description |
| Milestones | Milestone | Milestones | Milestone | Milestones | Enterprise Context | 120 | enterprise_id, project_id, name, status, name |
| Missions | Mission | Missions | Mission | Missions | Enterprise Context | 30 | enterprise_id, statement, enterprise.name, version, status, effective_from |
| Objectives | Objective | Objectives | Objective | Objectives | Enterprise Context | 50 | enterprise_id, goal_id, kpi_id, name, description, name, enterprise.name, goal.name, kpi.name |
| Organizations | Organization | Organizations | Organization | Organizations | Organization & Enterprise Scope | 10 | name, name |
| Partners | Partner | Partners | Partner | Partners | Finance | 50 | enterprise_id, name |
| Plans | Plan | Plans | Plan | Plans | Enterprise Context | 90 | strategy_id, name, description, name, strategy.name |
| Products | Product | Products | Product | Products | Enterprise Context | 180 | enterprise_id, name |
| Projects | Project | Projects | Project | Projects | Enterprise Context | 110 | enterprise_id, strategy_id, plan_id, initiative_id, name, description, status, name, enterprise.name, status |
| PublicationResults | PublicationResult | Publication Results | Publication Result | Publication Results | Marketing | 210 | enterprise.name, publication.id, publishingJob.id, provider, provider_status, external_id, external_url, correlation_id |
| PublicationSchedules | PublicationSchedule | Publication Schedules | Publication Schedule | Publication Schedules | Marketing | 190 | enterprise.name, publication.id, scheduled_at, status |
| Publications | Publication | Publications | Publication | Publications | Marketing | 180 | enterprise.name, contentItem.title, channel.name, socialAccount.name, approvalRequest.id, status, external_id, external_url |
| PublishingJobs | PublishingJob | Publishing Jobs | Publishing Job | Publishing Jobs | Marketing | 200 | enterprise.name, publication.id, idempotency_key, attempts, status, failure_code, failure_reason, started_at |
| RenderJobs | RenderJob | Render Jobs | Render Job | Render Jobs | Marketing | 150 | request.id, workflowJob.id, execution.id, external_job_id, status, failure_reason |
| RenderOutputs | RenderOutput | Render Outputs | Render Output | Render Outputs | Marketing | 160 | request.id, assetVersion.version, external_output_id, disk, path, mime_type, size, checksum |
| RenderRequests | RenderRequest | Render Requests | Render Request | Render Requests | Marketing | 140 | enterprise.name, contentItem.title, asset.name, sourceVersion.version, type, status, correlation_id, external_request_id |
| ReportMetricValues | ReportMetricValue | Report Metric Values | Report Metric Value | Report Metric Values | Reporting & Analytics | 30 | report_id, metric_definition_id, value, unit, calculation |
| ReportSnapshots | ReportSnapshot | Report Snapshots | Report Snapshot | Report Snapshots | Reporting & Analytics | 20 | report_id, captured_at, period_start, period_end, methodology_version, source_fingerprint |
| Reports | Report | Reports | Report | Reports | Reporting & Analytics | 10 | enterprise_id, report_type, status, period_start, period_end, generated_at, methodology_version |
| Revenues | Revenue | Revenues | Revenue | Revenues | Finance | 60 | enterprise_id, financial_account_id, transaction_id, financial_period_id, amount, currency, revenue_date, source, reference, description, enterprise_id, financial_account_id, transaction_id, financial_period_id, amount, currency, revenue_date, source, reference, description |
| Scripts | Script | Scripts | Script | Scripts | Marketing | 80 | content_item_id, title |
| SocialAccounts | SocialAccount | Social Accounts | Social Account | Social Accounts | Marketing | 60 | enterprise_id, channel_id, provider, name, external_id, status, enterprise.name, channel.name, provider, name, external_id, status |
| StatementEntries | StatementEntry | Statement Entries | Statement Entry | Statement Entries | Finance | 110 | organization_id, enterprise_id, statement_id, financial_account_id, transaction_id, source, source_reference, amount, entry_date, description, reference, metadata, organization_id, enterprise_id, statement_id, financial_account_id, transaction_id, source, source_reference, amount, entry_date, description, reference |
| Statements | Statement | Statements | Statement | Statements | Finance | 100 | organization_id, enterprise_id, financial_account_id, source, source_reference, statement_date, period_start, period_end, metadata, organization_id, enterprise_id, financial_account_id, source, source_reference, statement_date, period_start, period_end, metadata |
| Strategies | Strategy | Strategies | Strategie | Strategies | Enterprise Context | 80 | objective_id, name, description, name, objective.name |
| Tasks | Task | Tasks | Task | Tasks | Enterprise Context | 130 | enterprise_id, project_id, name, description, status, priority, name, project.name, status, priority |
| TransactionCategories | TransactionCategory | Transaction Categories | Transaction Category | Transaction Categories | Finance | 30 | enterprise_id, name, enterprise_id, name |
| Transactions | Transaction | Transactions | Transaction | Transactions | Finance | 80 | enterprise_id, financial_account_id, transaction_category_id, financial_period_id, amount, transaction_date, description, reference, enterprise_id, financial_account_id, transaction_category_id, financial_period_id, amount, transaction_date, description, reference |
| Transformations | Transformation | Transformations | Transformation | Transformations | Marketing | 170 | asset.name, sourceVersion.version, outputVersion.version, type |
| Users | User | Users | User | Users | Organization & Enterprise Scope | 20 | name, email, email_verified_at, two_factor_confirmed_at |
| Visions | Vision | Visions | Vision | Visions | Enterprise Context | 20 | enterprise_id, statement, enterprise.name, version, status, effective_from |
| WorkItems | WorkItem | Work Items | Work Item | Work Items | Enterprise Context | 140 | enterprise_id, project_id, name, description, status, name |
| WorkflowExecutions | WorkflowExecution | Workflow Executions | Workflow Execution | Workflow Executions | Workflow Flow | 50 | workflow_id, workflow_version_id, status, current_stage_key, started_at, completed_at, failure_reason |
| WorkflowStages | WorkflowStage | Workflow Stages | Workflow Stage | Workflow Stages | Workflow Flow | 30 | workflow_id, key, name, sequence, repeatable |
| WorkflowVersions | WorkflowVersion | Workflow Versions | Workflow Version | Workflow Versions | Workflow Flow | 20 | workflow_id, enterprise_id, version, name, status, published_at, retired_at |
| Workflows | Workflow | Workflows | Workflow | Workflows | Workflow Flow | 10 | enterprise_id, name, canonical_key, purpose, status, execution_policy, completion_criteria, stages, key, name, sequence, expert_slugs, capability_slugs, repeatable, input_contract, output_contract, name, canonical_key, enterprise.name, publishedVersion.version, status, created_at |
