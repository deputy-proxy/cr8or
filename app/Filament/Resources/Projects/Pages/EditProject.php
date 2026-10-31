<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Services\DomainResourceService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class EditProject extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = ProjectResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Project || (int) $data['enterprise_id'] !== (int) $record->enterprise_id) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        return app(DomainResourceService::class)->updateProject(
            auth()->user(),
            $record,
            array_intersect_key($data, array_flip(['strategy_id', 'initiative_id', 'name', 'description', 'status'])),
        );
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
