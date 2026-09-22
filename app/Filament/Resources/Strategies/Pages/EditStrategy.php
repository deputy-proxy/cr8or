<?php

namespace App\Filament\Resources\Strategies\Pages;

use App\Filament\Resources\Strategies\StrategyResource;
use App\Models\Strategy;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditStrategy extends EditRecord
{
    protected static string $resource = StrategyResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Strategy $record */
        $record = $this->record;

        if ((int) $data['objective_id'] !== $record->objective_id) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

