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
        'Organization',
        'Strategy',
        'Intelligence',
        'Integrations',
        'Content',
        'Media',
        'Publishing',
        'Operations',
    ]);
});

it('groups every discovered resource into an approved domain', function () {
    $allowedGroups = [
        'Organization',
        'Strategy',
        'Intelligence',
        'Integrations',
        'Content',
        'Media',
        'Publishing',
        'Operations',
    ];

    $resourceFiles = glob(app_path('Filament/Resources/*/*Resource.php'));

    expect($resourceFiles)->not->toBeFalse();

    foreach ($resourceFiles as $resourceFile) {
        $directory = basename(dirname($resourceFile));
        $class = basename($resourceFile, '.php');
        $resourceClass = "App\\Filament\\Resources\\{$directory}\\{$class}";

        expect(class_exists($resourceClass))->toBeTrue();
        expect($resourceClass::getNavigationGroup())->toBeIn($allowedGroups);
    }
});

it('uses the agreed domain labels for the primary navigation resources', function () {
    expect(OrganizationResource::getNavigationLabel())->toBe('Organization')
        ->and(EnterpriseResource::getNavigationGroup())->toBe('Organization')
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
        ->and(ApprovalRequestResource::getNavigationLabel())->toBe('Approvals')
        ->and(AgentCollaborationReport::getNavigationGroup())->toBe('Intelligence');
});