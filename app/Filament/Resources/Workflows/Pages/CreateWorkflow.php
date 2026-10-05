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
        $enterpriseSpecific = (bool) ($data['enterprise_specific'] ?? true);

        if ($enterpriseSpecific) {
            $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
            Gate::authorize('createForEnterprise', [Workflow::class, $enterprise]);
        } else {
            Gate::authorize('create', Workflow::class);
            $data['enterprise_id'] = null;
        }

        $data['enterprise_specific'] = $enterpriseSpecific;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Workflow $workflow */
        $workflow = $this->record;

        app(WorkflowStageConfigurationService::class)->synchronizeCapabilityContracts($workflow);
    }
}