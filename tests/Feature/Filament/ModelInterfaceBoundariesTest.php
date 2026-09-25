<?php

use App\Filament\Resources\AgentDecisions\AgentDecisionResource;
use App\Filament\Resources\AgentDelegations\AgentDelegationResource;
use App\Filament\Resources\AgentExecutions\AgentExecutionResource;
use App\Filament\Resources\ApprovalRequests\ApprovalRequestResource;
use App\Filament\Resources\AssetVersions\AssetVersionResource;
use App\Filament\Resources\BusinessHealthResults\BusinessHealthResultResource;
use App\Filament\Resources\Executions\ExecutionResource;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Filament\Resources\GenerationJobs\GenerationJobResource;
use App\Filament\Resources\GenerationRequests\GenerationRequestResource;
use App\Filament\Resources\IntegrationJobs\IntegrationJobResource;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\MediaMetadata\MediaMetadataResource;
use App\Filament\Resources\PublicationResults\PublicationResultResource;
use App\Filament\Resources\PublishingJobs\PublishingJobResource;
use App\Filament\Resources\RenderJobs\RenderJobResource;
use App\Filament\Resources\RenderOutputs\RenderOutputResource;
use App\Filament\Resources\RenderRequests\RenderRequestResource;
use App\Filament\Resources\Transformations\TransformationResource;
use App\Filament\Resources\Workflows\WorkflowResource;

it('keeps operational and historical resources free of unrestricted CRUD pages', function () {
    foreach ([
        AgentDecisionResource::class,
        AgentDelegationResource::class,
        AgentExecutionResource::class,
        ApprovalRequestResource::class,
        AssetVersionResource::class,
        BusinessHealthResultResource::class,
        ExecutionResource::class,
        FinancialReportResource::class,
        GenerationJobResource::class,
        GenerationRequestResource::class,
        IntegrationJobResource::class,
        JobResource::class,
        MediaMetadataResource::class,
        PublicationResultResource::class,
        PublishingJobResource::class,
        RenderJobResource::class,
        RenderOutputResource::class,
        RenderRequestResource::class,
        TransformationResource::class,
        WorkflowResource::class,
    ] as $resource) {
        expect($resource::canCreate())->toBeFalse()
            ->and($resource::getPages())->not->toHaveKey('create')
            ->and($resource::getPages())->not->toHaveKey('edit');
    }
});