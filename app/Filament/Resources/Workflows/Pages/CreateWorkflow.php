<?php

namespace App\Filament\Resources\Workflows\Pages;

use App\Filament\Resources\Workflows\WorkflowResource;
use App\Models\Enterprise;
use App\Models\Workflow;
use App\Services\WorkflowStageConfigurationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateWorkflow extends CreateRecord
{
    protected static string $resource = WorkflowResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [Workflow::class, $enterprise]);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Workflow $workflow */
        $workflow = $this->record;

        app(WorkflowStageConfigurationService::class)->synchronizeCapabilityContracts($workflow);
    }
}