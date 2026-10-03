<?php

use App\Filament\Pages\AgentCollaborationReport;
use App\Filament\Resources\AgentDescriptors\AgentDescriptorResource;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\ContentSeries\ContentSeriesResource;
use App\Filament\Resources\Enterprises\EnterpriseResource;
use App\Filament\Resources\Executions\ExecutionResource;
use App\Filament\Resources\ExpertDescriptors\ExpertDescriptorResource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\Objectives\ObjectiveResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Strategies\StrategyResource;
use App\Filament\Resources\WorkItems\WorkItemResource;
use Filament\Facades\Filament;

it('defines the CR8OR navigation groups in the required order', function () {
    expect(Filament::getNavigationGroups())->toBe([
        'Organization & Enterprise Scope',
        'Enterprise Context',
        'Knowledge Management',
        'Agentic Flow',
        'Workflow Flow',
        'Marketing',
        'Finance',
        'Reporting & Analytics',
        'Integrations & External Systems',
    ]);
});

it('maps every discovered Filament resource to exactly one current navigation group', function () {
    $expectedGroups = [
        'Organizations' => 'Organization & Enterprise Scope', 'Users' => 'Organization & Enterprise Scope', 'Memberships' => 'Organization & Enterprise Scope', 'Enterprises' => 'Organization & Enterprise Scope', 'Assignments' => 'Organization & Enterprise Scope',
        'EnterpriseContexts' => 'Enterprise Context', 'Visions' => 'Enterprise Context', 'Missions' => 'Enterprise Context', 'Goals' => 'Enterprise Context', 'Objectives' => 'Enterprise Context', 'Kpis' => 'Enterprise Context', 'MetricDefinitions' => 'Enterprise Context', 'Strategies' => 'Enterprise Context', 'Plans' => 'Enterprise Context', 'Initiatives' => 'Enterprise Context', 'Projects' => 'Enterprise Context', 'Milestones' => 'Enterprise Context', 'Tasks' => 'Enterprise Context', 'WorkItems' => 'Enterprise Context', 'Decisions' => 'Enterprise Context', 'EnterpriseDecisions' => 'Enterprise Context', 'Competitors' => 'Enterprise Context', 'Products' => 'Enterprise Context',
        'KnowledgeContexts' => 'Knowledge Management', 'KnowledgeSources' => 'Knowledge Management', 'KnowledgeDocuments' => 'Knowledge Management', 'KnowledgeItems' => 'Knowledge Management', 'KnowledgeVersions' => 'Knowledge Management', 'KnowledgeSpecifications' => 'Knowledge Management', 'KnowledgeReferences' => 'Knowledge Management', 'KnowledgeIndexRecords' => 'Knowledge Management', 'KnowledgeIndexUnits' => 'Knowledge Management', 'KnowledgeEmbeddings' => 'Knowledge Management',
        'AgentDescriptors' => 'Agentic Flow', 'ExpertDescriptors' => 'Agentic Flow', 'AgentRuntimePolicies' => 'Agentic Flow', 'AgentAssignments' => 'Agentic Flow', 'AgentExecutions' => 'Agentic Flow', 'AgentExecutionSteps' => 'Agentic Flow', 'AgentExecutionEventRecords' => 'Agentic Flow', 'AgentDelegations' => 'Agentic Flow', 'AgentDecisions' => 'Agentic Flow', 'AgentEpisodicMemories' => 'Agentic Flow', 'AgentSemanticMemories' => 'Agentic Flow', 'AgentSemanticMemoryVersions' => 'Agentic Flow', 'ApprovalPolicies' => 'Agentic Flow', 'ApprovalRequests' => 'Agentic Flow', 'ApprovalDecisions' => 'Agentic Flow',
        'Workflows' => 'Workflow Flow', 'WorkflowVersions' => 'Workflow Flow', 'WorkflowStages' => 'Workflow Flow', 'WorkflowExecutions' => 'Workflow Flow', 'Jobs' => 'Workflow Flow', 'Executions' => 'Workflow Flow', 'Dependencies' => 'Workflow Flow',
        'MarketingStrategies' => 'Marketing', 'Campaigns' => 'Marketing', 'ContentSeries' => 'Marketing', 'ContentItems' => 'Marketing', 'Scripts' => 'Marketing', 'Audiences' => 'Marketing', 'Channels' => 'Marketing', 'SocialAccounts' => 'Marketing', 'Publications' => 'Marketing', 'PublicationSchedules' => 'Marketing', 'PublishingJobs' => 'Marketing', 'PublicationResults' => 'Marketing', 'Assets' => 'Marketing', 'AssetVersions' => 'Marketing', 'MediaMetadata' => 'Marketing', 'RenderRequests' => 'Marketing', 'RenderJobs' => 'Marketing', 'RenderOutputs' => 'Marketing', 'Transformations' => 'Marketing', 'GenerationRequests' => 'Marketing', 'GenerationJobs' => 'Marketing',
        'FinancialAccounts' => 'Finance', 'FinancialPeriods' => 'Finance', 'TransactionCategories' => 'Finance', 'Transactions' => 'Finance', 'Revenues' => 'Finance', 'Expenses' => 'Finance', 'Budgets' => 'Finance', 'Statements' => 'Finance', 'StatementEntries' => 'Finance', 'Customers' => 'Finance', 'Partners' => 'Finance', 'Invoices' => 'Finance', 'FinancialReports' => 'Finance', 'BusinessHealthResults' => 'Finance',
        'Reports' => 'Reporting & Analytics', 'ReportSnapshots' => 'Reporting & Analytics', 'ReportMetricValues' => 'Reporting & Analytics',
        'IntegrationConnections' => 'Integrations & External Systems', 'IntegrationJobs' => 'Integrations & External Systems', 'IntegrationResults' => 'Integrations & External Systems', 'ExternalResources' => 'Integrations & External Systems', 'CommandWebhookDeliveries' => 'Integrations & External Systems',
    ];

    $resourceFiles = glob(app_path('Filament/Resources/*/*Resource.php'));
    expect($resourceFiles)->not->toBeFalse();

    $directories = array_map(fn (string $file): string => basename(dirname($file)), $resourceFiles);
    sort($directories);
    $expectedDirectories = array_keys($expectedGroups);
    sort($expectedDirectories);

    expect($directories)->toBe($expectedDirectories)->toHaveCount(98);

    foreach ($expectedGroups as $directory => $group) {
        $resourceFilesForDirectory = glob(app_path("Filament/Resources/{$directory}/*Resource.php"));
        expect($resourceFilesForDirectory)->toHaveCount(1);

        $class = 'App\\Filament\\Resources\\'.$directory.'\\'.basename($resourceFilesForDirectory[0], '.php');

        expect(class_exists($class))->toBeTrue();
        expect($class::getNavigationGroup())->toBe($group);
    }
});

it('orders every resource in the canonical logical domain flow', function () {
    $expectedOrder = [
        'Organization & Enterprise Scope' => ['Organizations', 'Users', 'Memberships', 'Enterprises', 'Assignments'],
        'Enterprise Context' => ['EnterpriseContexts', 'Visions', 'Missions', 'Goals', 'Objectives', 'Kpis', 'MetricDefinitions', 'Strategies', 'Plans', 'Initiatives', 'Projects', 'Milestones', 'Tasks', 'WorkItems', 'Decisions', 'EnterpriseDecisions', 'Competitors', 'Products'],
        'Knowledge Management' => ['KnowledgeContexts', 'KnowledgeSources', 'KnowledgeDocuments', 'KnowledgeItems', 'KnowledgeVersions', 'KnowledgeSpecifications', 'KnowledgeReferences', 'KnowledgeIndexRecords', 'KnowledgeIndexUnits', 'KnowledgeEmbeddings'],
        'Agentic Flow' => ['AgentDescriptors', 'ExpertDescriptors', 'AgentRuntimePolicies', 'AgentAssignments', 'AgentExecutions', 'AgentExecutionSteps', 'AgentExecutionEventRecords', 'AgentDelegations', 'AgentDecisions', 'AgentEpisodicMemories', 'AgentSemanticMemories', 'AgentSemanticMemoryVersions', 'ApprovalPolicies', 'ApprovalRequests', 'ApprovalDecisions'],
        'Workflow Flow' => ['Workflows', 'WorkflowVersions', 'WorkflowStages', 'Dependencies', 'WorkflowExecutions', 'Executions', 'Jobs'],
        'Marketing' => ['MarketingStrategies', 'Campaigns', 'ContentSeries', 'Audiences', 'Channels', 'SocialAccounts', 'ContentItems', 'Scripts', 'Assets', 'AssetVersions', 'MediaMetadata', 'GenerationRequests', 'GenerationJobs', 'RenderRequests', 'RenderJobs', 'RenderOutputs', 'Transformations', 'Publications', 'PublicationSchedules', 'PublishingJobs', 'PublicationResults'],
        'Finance' => ['FinancialAccounts', 'FinancialPeriods', 'TransactionCategories', 'Customers', 'Partners', 'Revenues', 'Expenses', 'Transactions', 'Budgets', 'Statements', 'StatementEntries', 'Invoices', 'FinancialReports', 'BusinessHealthResults'],
        'Reporting & Analytics' => ['Reports', 'ReportSnapshots', 'ReportMetricValues'],
        'Integrations & External Systems' => ['IntegrationConnections', 'ExternalResources', 'IntegrationJobs', 'IntegrationResults', 'CommandWebhookDeliveries'],
    ];

    foreach ($expectedOrder as $group => $directories) {
        $actual = [];
        foreach ($directories as $position => $directory) {
            $files = glob(app_path("Filament/Resources/{$directory}/*Resource.php"));
            expect($files)->toHaveCount(1);
            $class = 'App\\Filament\\Resources\\'.$directory.'\\'.basename($files[0], '.php');
            expect($class::getNavigationGroup())->toBe($group)
                ->and($class::getNavigationSort())->toBe(($position + 1) * 10);
            $actual[] = $directory;
        }

        expect($actual)->toBe($directories);
    }
});

it('keeps non-resource Filament pages inside the current navigation taxonomy', function () {
    expect(AgentCollaborationReport::getNavigationGroup())->toBe('Reporting & Analytics');
});

it('uses the agreed domain labels for the primary navigation resources', function () {
    expect(OrganizationResource::getNavigationLabel())->toBe('Organization')
        ->and(EnterpriseResource::getNavigationGroup())->toBe('Organization & Enterprise Scope')
        ->and(ObjectiveResource::getNavigationLabel())->toBe('Objectives')
        ->and(StrategyResource::getNavigationLabel())->toBe('Strategies')
        ->and(InitiativeResource::getNavigationLabel())->toBe('Initiatives')
        ->and(WorkItemResource::getNavigationLabel())->toBe('Work Items')
        ->and(AgentDescriptorResource::getNavigationLabel())->toBe('Agents')
        ->and(ExpertDescriptorResource::getNavigationLabel())->toBe('Experts')
        ->and(CampaignResource::getNavigationLabel())->toBe('Campaigns')
        ->and(ContentSeriesResource::getNavigationLabel())->toBe('Series')
        ->and(ContentItemResource::getNavigationLabel())->toBe('Items')
        ->and(AudienceResource::getNavigationLabel())->toBe('Audiences')
        ->and(ChannelResource::getNavigationLabel())->toBe('Channels')
        ->and(ExecutionResource::getNavigationLabel())->toBe('Executions')
        ->and(JobResource::getNavigationLabel())->toBe('Jobs')
        ->and(ApprovalRequestResource::getNavigationLabel())->toBe('Approvals');
});