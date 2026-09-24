<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Enterprise;
use App\Models\Project;
use App\Models\Task;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [Task::class, $enterprise]);
        if (! empty($data['project_id']) && Project::query()->whereKey($data['project_id'])->value('enterprise_id') !== $enterprise->id) {
            throw ValidationException::withMessages(['project_id' => 'The project must belong to the selected enterprise.']);
        }

        return $data;
    }
}
