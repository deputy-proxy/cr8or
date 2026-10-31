<?php

namespace App\Filament\Resources\Milestones\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Milestones\MilestoneResource;
use App\Models\Enterprise;
use App\Models\Milestone;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateMilestone extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = MilestoneResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [Milestone::class, $enterprise]);

        return $data;
    }
}
