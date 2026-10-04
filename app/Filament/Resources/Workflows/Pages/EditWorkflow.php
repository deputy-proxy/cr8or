<?php

namespace App\Filament\Resources\Workflows\Pages;

use App\Filament\Resources\Workflows\WorkflowResource;
use App\Models\Workflow;
use App\Services\WorkflowStageConfigurationService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditWorkflow extends EditRecord
{
    protected static string $resource = WorkflowResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Workflow $record */
        $record = $this->record;

        if ((int) $data['enterprise_id'] !== (int) $record->enterprise_id) {
            throw new AuthorizationException('Cannot reassign a workflow to another enterprise.');
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Workflow $workflow */
        $workflow = $this->record;

        app(WorkflowStageConfigurationService::class)->synchronizeCapabilityContracts($workflow);
    }
}