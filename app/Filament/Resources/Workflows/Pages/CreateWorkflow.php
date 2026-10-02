<?php

namespace App\Filament\Resources\Workflows\Pages;

use App\Filament\Resources\Workflows\WorkflowResource;
use App\Models\Enterprise;
use App\Models\Workflow;
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
}
