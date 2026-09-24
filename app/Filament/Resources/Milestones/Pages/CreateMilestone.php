<?php

namespace App\Filament\Resources\Milestones\Pages;

use App\Filament\Resources\Milestones\MilestoneResource;
use App\Models\Enterprise;
use App\Models\Milestone;
use App\Models\Project;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateMilestone extends CreateRecord
{
    protected static string $resource = MilestoneResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [Milestone::class, $enterprise]);
        if (Project::query()->whereKey($data['project_id'])->value('enterprise_id') !== $enterprise->id) {
            throw ValidationException::withMessages(['project_id' => 'The project must belong to the selected enterprise.']);
        }

        return $data;
    }
}
