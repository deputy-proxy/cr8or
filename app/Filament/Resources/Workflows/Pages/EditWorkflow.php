<?php

namespace App\Filament\Resources\Workflows\Pages;

use App\Filament\Resources\Workflows\WorkflowResource;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Model;
use App\Services\WorkflowStageConfigurationService;
use Filament\Resources\Pages\EditRecord;

class EditWorkflow extends EditRecord
{
    protected static string $resource = WorkflowResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Workflow $workflow */
        $workflow = $record;

        $enterpriseSpecific = (bool) ($data['enterprise_specific'] ?? true);
        $enterpriseId = isset($data['enterprise_id']) && $data['enterprise_id'] !== ''
            ? (int) $data['enterprise_id']
            : null;

        try {
            if (
                $enterpriseSpecific !== $workflow->isEnterpriseSpecific()
                || $enterpriseId !== $workflow->enterprise_id
            ) {
                $enterprise = $enterpriseId === null
                    ? null
                    : AppModelsEnterprise::query()->findOrFail($enterpriseId);

                $workflow->changeScope($enterpriseSpecific, $enterprise);
            }
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'enterprise_specific' => $exception->getMessage(),
            ]);
        }

        unset($data['enterprise_specific'], $data['enterprise_id']);

        $workflow->fill($data);
        $workflow->save();

        return $workflow;
    }

    protected function afterSave(): void
    {
        /** @var Workflow $workflow */
        $workflow = $this->record;

        app(WorkflowStageConfigurationService::class)->synchronizeCapabilityContracts($workflow);
    }
}