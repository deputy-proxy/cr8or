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
use App\Filament\Resources\ExpertDescriptors\ExpertDescriptorResource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Objectives\ObjectiveResource;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Strategies\StrategyResource;
use App\Filament\Resources\WorkItems\WorkItemResource;
use Filament\Facades\Filament;

function expectedNavigation(): array
{
    return [
        'Organization & Access' => ['Organizations', 'Users', 'Memberships', 'Enterprises'],
        'Strategy & Planning' => ['EnterpriseContexts', 'Visions', 'Missions', 'Goals', 'Objectives', 'Kpis', 'MetricDefinitions', 'Strategies', 'Plans', 'Competitors', 'Products'],
        'Work Management' => ['Initiatives', 'Projects', 'Milestones', 'Tasks', 'WorkItems', 'Dependencies', 'Assignments', 'Decisions', 'EnterpriseDecisions'],
        'Knowledge' => ['KnowledgeContexts', 'KnowledgeSources', 'KnowledgeDocuments', 'KnowledgeItems', 'KnowledgeVersions', 'KnowledgeSpecifications', 'KnowledgeReferences', 'KnowledgeIndexRecords', 'KnowledgeIndexUnits', 'KnowledgeEmbeddings'],
        'Agent Configuration' => ['AgentDescriptors', 'ExpertDescriptors', 'AgentRuntimePolicies', 'AgentAssignments'],
        'Agent Operations & Governance' => ['AgentExecutions', 'AgentDelegations', 'AgentDecisions', 'ApprovalRequests', 'ApprovalPolicies', 'ApprovalDecisions', 'AgentExecutionSteps', 'AgentExecutionEventRecords', 'AgentEpisodicMemories', 'AgentSemanticMemories', 'AgentSemanticMemoryVersions'],
        'Workflows' => ['Workflows', 'WorkflowVersions', 'WorkflowStages', 'WorkflowExecutions'],
        'Marketing & Content' => ['MarketingStrategies', 'Campaigns', 'ContentSeries', 'Audiences', 'Channels', 'SocialAccounts', 'ContentItems', 'Scripts'],
        'Media Production' => ['Assets', 'GenerationRequests', 'GenerationJobs', 'RenderRequests', 'RenderJobs', 'RenderOutputs', 'Transformations', 'MediaMetadata', 'AssetVersions'],
        'Publishing' => ['Publications', 'PublicationSchedules', 'PublishingJobs', 'PublicationResults'],
        'Finance' => ['FinancialAccounts', 'FinancialPeriods', 'TransactionCategories', 'Customers', 'Partners', 'Revenues', 'Expenses', 'Transactions', 'Budgets', 'Statements', 'StatementEntries', 'Invoices', 'FinancialReports', 'BusinessHealthResults'],
        'Reporting & Analytics' => ['Reports', 'ReportSnapshots', 'ReportMetricValues'],
        'Integrations' => ['IntegrationConnections', 'ExternalResources', 'IntegrationJobs', 'IntegrationResults', 'CommandWebhookDeliveries'],
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

    expect($directories)->toBe($expectedDirectories)->toHaveCount(96);

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

it('places the custom agent collaboration report in Reporting & Analytics after report records', function () {
    expect(AgentCollaborationReport::getNavigationGroup())->toBe('Reporting & Analytics')
        ->and(AgentCollaborationReport::getNavigationSort())->toBe(40);
});

it('preserves agreed labels for primary navigation resources', function () {
    expect(OrganizationResource::getNavigationLabel())->toBe('Organizations')
        ->and(EnterpriseResource::getNavigationGroup())->toBe('Organization & Access')
        ->and(ObjectiveResource::getNavigationLabel())->toBe('Objectives')
        ->and(StrategyResource::getNavigationLabel())->toBe('Strategies')
        ->and(InitiativeResource::getNavigationLabel())->toBe('Initiatives')
        ->and(WorkItemResource::getNavigationLabel())->toBe('Work Items')
        ->and(AgentDescriptorResource::getNavigationLabel())->toBe('Agents')
        ->and(ExpertDescriptorResource::getNavigationLabel())->toBe('Experts')
        ->and(CampaignResource::getNavigationLabel())->toBe('Campaigns')
        ->and(ContentSeriesResource::getNavigationLabel())->toBe('Content Series')
        ->and(ContentItemResource::getNavigationLabel())->toBe('Content Items')
        ->and(AudienceResource::getNavigationLabel())->toBe('Audiences')
        ->and(ChannelResource::getNavigationLabel())->toBe('Channels')
        ->and(ApprovalRequestResource::getNavigationLabel())->toBe('Approval Requests');
});
