<?php

use App\Filament\Pages\AgentCollaborationReport;
use App\Filament\Pages\EnterprisePortfolioDashboard;
use App\Filament\Pages\WorkOverview;
use App\Filament\Resources\AgentDescriptors\AgentDescriptorResource;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\ContentSeries\ContentSeriesResource;
use App\Filament\Resources\Decisions\DecisionResource;
use App\Filament\Resources\EnterpriseCategories\EnterpriseCategoryResource;
use App\Filament\Resources\EnterpriseGroups\EnterpriseGroupResource;
use App\Filament\Resources\Enterprises\EnterpriseResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\ExpertDescriptors\ExpertDescriptorResource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Issues\IssueResource;
use App\Filament\Resources\Objectives\ObjectiveResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Strategies\StrategyResource;
use App\Filament\Resources\Tasks\TaskResource;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

function expectedNavigation(): array
{
    return [
        'Organization & Access' => ['Organizations', 'Users', 'Memberships'],
        'Enterprises & Products' => ['Enterprises', 'EnterpriseContexts', 'EnterpriseGroups', 'EnterpriseCategories', 'EnterpriseDecisions', 'Competitors', 'Products'],
        'Strategy & Execution Planning' => ['Strategies', 'Goals', 'Objectives', 'Kpis', 'Plans', 'Initiatives', 'Projects', 'Milestones', 'Tasks', 'Dependencies', 'Assignments', 'Decisions', 'Issues'],
        'Agent Governance & Operations' => ['AgentDescriptors', 'ExpertDescriptors', 'AgentRuntimePolicies', 'AgentAssignments', 'AgentExecutions', 'AgentDelegations', 'AgentDecisions', 'ApprovalRequests', 'ApprovalPolicies', 'ApprovalDecisions', 'AgentExecutionSteps', 'AgentExecutionEventRecords', 'AgentEpisodicMemories', 'AgentSemanticMemories', 'AgentSemanticMemoryVersions'],
        'Media Production & Publishing' => ['Assets', 'GenerationRequests', 'GenerationJobs', 'RenderRequests', 'RenderJobs', 'RenderOutputs', 'Transformations', 'MediaMetadata', 'AssetVersions', 'Publications', 'PublicationSchedules', 'PublishingJobs', 'PublicationResults'],
        'Knowledge & Documentation' => ['KnowledgeContexts', 'KnowledgeSources', 'KnowledgeDocuments', 'KnowledgeItems', 'KnowledgeVersions', 'KnowledgeSpecifications', 'KnowledgeReferences', 'KnowledgeIndexRecords', 'KnowledgeIndexUnits', 'KnowledgeEmbeddings'],
        'Workflows & Integrations' => ['Workflows', 'WorkflowVersions', 'WorkflowStages', 'WorkflowExecutions', 'IntegrationConnections', 'ExternalResources', 'IntegrationJobs', 'IntegrationResults', 'CommandWebhookDeliveries', 'Events'],
        'Marketing' => ['MarketingStrategies', 'Campaigns', 'ContentSeries', 'Audiences', 'Channels', 'SocialAccounts', 'ContentItems', 'Scripts'],
        'Finance' => ['FinancialAccounts', 'FinancialPeriods', 'TransactionCategories', 'Customers', 'Partners', 'Revenues', 'Expenses', 'Transactions', 'Budgets', 'Statements', 'StatementEntries', 'Invoices'],
        'Reporting' => ['Reports', 'ReportSnapshots', 'ReportMetricValues', 'MetricDefinitions', 'FinancialReports', 'BusinessHealthResults'],
    ];
}

it('defines the CR8OR navigation groups in the required order', function () {
    expect(Filament::getNavigationGroups())->toBe(array_keys(expectedNavigation()));
});

it('maps every discovered Filament resource to exactly one approved navigation group', function () {
    $expectedGroups = expectedNavigation();
    $resourceFiles = glob(app_path('Filament/Resources/*/*Resource.php'));

    expect($resourceFiles)->not->toBeFalse();

    $directories = array_map(fn (string $file): string => basename(dirname($file)), $resourceFiles);
    sort($directories);
    $expectedDirectories = array_merge(...array_values($expectedGroups));
    sort($expectedDirectories);

    expect($directories)->toBe($expectedDirectories)->toHaveCount(97);

    foreach ($expectedGroups as $group => $resources) {
        foreach ($resources as $resource) {
            $files = glob(app_path("Filament/Resources/{$resource}/*Resource.php"));
            expect($files)->toHaveCount(1);

            $class = 'App\\Filament\\Resources\\'.$resource.'\\'.basename($files[0], '.php');

            expect(class_exists($class))->toBeTrue()
                ->and($class::getNavigationGroup())->toBe($group);
        }
    }
});

it('orders resources by user workflow within each navigation group', function () {
    foreach (expectedNavigation() as $group => $resources) {
        foreach ($resources as $position => $resource) {
            $files = glob(app_path("Filament/Resources/{$resource}/*Resource.php"));
            expect($files)->toHaveCount(1);

            $class = 'App\\Filament\\Resources\\'.$resource.'\\'.basename($files[0], '.php');

            expect($class::getNavigationGroup())->toBe($group)
                ->and($class::getNavigationSort())->toBe(($position + 1) * 10, "Resource {$resource}");
        }
    }
});

it('places Work Overview within Strategy & Execution Planning after Decisions and before Issues', function () {
    expect(WorkOverview::getNavigationGroup())->toBe('Strategy & Execution Planning')
        ->and(WorkOverview::getNavigationSort())->toBe(125)
        ->and(WorkOverview::getNavigationLabel())->toBe('Work Overview')
        ->and(DecisionResource::getNavigationSort())->toBeLessThan(WorkOverview::getNavigationSort())
        ->and(WorkOverview::getNavigationSort())->toBeLessThan(IssueResource::getNavigationSort())
        ->and(Route::has('filament.admin.pages.work-overview'))->toBeTrue();
});

it('places the Enterprise Portfolio dashboard between Enterprises and Enterprise Contexts', function () {
    expect(EnterprisePortfolioDashboard::getNavigationGroup())->toBe('Enterprises & Products')
        ->and(EnterprisePortfolioDashboard::getNavigationSort())->toBe(15)
        ->and(Route::has('filament.admin.pages.enterprise-portfolio-dashboard'))->toBeTrue()
        ->and(EnterpriseResource::getNavigationSort())->toBeLessThan(EnterprisePortfolioDashboard::getNavigationSort())
        ->and(EnterprisePortfolioDashboard::getNavigationSort())->toBeLessThan(\App\Filament\Resources\EnterpriseContexts\EnterpriseContextResource::getNavigationSort())
        ->and(EnterpriseGroupResource::getNavigationSort())->toBeGreaterThan(EnterprisePortfolioDashboard::getNavigationSort())
        ->and(EnterpriseCategoryResource::getNavigationSort())->toBeGreaterThan(EnterprisePortfolioDashboard::getNavigationSort());
});

it('places the agent collaboration report in Reporting between metric values and metric definitions', function () {
    expect(AgentCollaborationReport::getNavigationGroup())->toBe('Reporting')
        ->and(AgentCollaborationReport::getNavigationSort())->toBe(35)
        ->and(\App\Filament\Resources\ReportMetricValues\ReportMetricValueResource::getNavigationSort())->toBeLessThan(AgentCollaborationReport::getNavigationSort())
        ->and(AgentCollaborationReport::getNavigationSort())->toBeLessThan(\App\Filament\Resources\MetricDefinitions\MetricDefinitionResource::getNavigationSort());
});

it('preserves agreed labels for primary navigation resources', function () {
    expect(OrganizationResource::getNavigationLabel())->toBe('Organizations')
        ->and(EnterpriseResource::getNavigationGroup())->toBe('Enterprises & Products')
        ->and(ObjectiveResource::getNavigationLabel())->toBe('Objectives')
        ->and(StrategyResource::getNavigationLabel())->toBe('Strategies')
        ->and(InitiativeResource::getNavigationLabel())->toBe('Initiatives')
        ->and(TaskResource::getNavigationLabel())->toBe('Tasks')
        ->and(AgentDescriptorResource::getNavigationLabel())->toBe('Agents')
        ->and(ExpertDescriptorResource::getNavigationLabel())->toBe('Experts')
        ->and(CampaignResource::getNavigationLabel())->toBe('Campaigns')
        ->and(ContentSeriesResource::getNavigationLabel())->toBe('Content Series')
        ->and(ContentItemResource::getNavigationLabel())->toBe('Content Items')
        ->and(AudienceResource::getNavigationLabel())->toBe('Audiences')
        ->and(ChannelResource::getNavigationLabel())->toBe('Channels')
        ->and(ApprovalRequestResource::getNavigationLabel())->toBe('Approval Requests')
        ->and(EventResource::getNavigationLabel())->toBe('Events')
        ->and(IssueResource::getNavigationLabel())->toBe('Issues');
});

it('registers read-only Events and Issues resources in their intended navigation groups', function () {
    expect(EventResource::getNavigationGroup())->toBe('Workflows & Integrations')
        ->and(IssueResource::getNavigationGroup())->toBe('Strategy & Execution Planning')
        ->and(Route::has('filament.admin.resources.events.index'))->toBeTrue()
        ->and(Route::has('filament.admin.resources.issues.index'))->toBeTrue()
        ->and(array_keys(EventResource::getPages()))->toBe(['index'])
        ->and(array_keys(IssueResource::getPages()))->toBe(['index']);
});
