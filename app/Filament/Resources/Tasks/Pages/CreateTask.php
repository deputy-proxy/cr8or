<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Project;
use App\Models\Task;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateTask extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = TaskResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [Task::class, $enterprise]);

        if (! empty($data['project_id']) && ! empty($data['initiative_id'])) {
            throw ValidationException::withMessages(['project_id' => 'A Task can belong to a Project or Initiative, not both.']);
        }

        if (! empty($data['project_id']) && (int) Project::query()->whereKey($data['project_id'])->value('enterprise_id') !== (int) $enterprise->id) {
            throw ValidationException::withMessages(['project_id' => 'The Project must belong to the selected Enterprise.']);
        }

        if (! empty($data['initiative_id']) && (int) Initiative::query()->whereKey($data['initiative_id'])->value('enterprise_id') !== (int) $enterprise->id) {
            throw ValidationException::withMessages(['initiative_id' => 'The Initiative must belong to the selected Enterprise.']);
        }

        return $data;
    }
}
