<?php

namespace App\Filament\Resources\Initiatives\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Models\Initiative;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateInitiative extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = InitiativeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $strategy = \App\Models\Strategy::query()->with('objective.enterprise')->findOrFail((int) $data['strategy_id']);
        Gate::authorize('createForStrategy', [Initiative::class, $strategy]);

        return $data;
    }
}
