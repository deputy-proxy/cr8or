<?php

namespace App\Filament\Resources\MetricDefinitions\Pages;

use App\Filament\Resources\MetricDefinitions\MetricDefinitionResource;
use App\Models\MetricDefinition;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditMetricDefinition extends EditRecord
{
    protected static string $resource = MetricDefinitionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var MetricDefinition $record */
        $record = $this->record;

        if (isset($data['enterprise_id']) && (int) $data['enterprise_id'] !== $record->enterprise_id) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        return $data;
    }
}
