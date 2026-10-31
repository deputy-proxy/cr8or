<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Enterprise;
use App\Services\DomainResourceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProject extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = ProjectResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);

        return app(DomainResourceService::class)->createProject(auth()->user(), $enterprise, $data);
    }
}
