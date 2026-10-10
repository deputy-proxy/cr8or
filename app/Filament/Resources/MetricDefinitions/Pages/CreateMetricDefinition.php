<?php

namespace App\Filament\Resources\MetricDefinitions\Pages;

use App\Filament\Resources\MetricDefinitions\MetricDefinitionResource;
use App\Models\Enterprise;
use App\Models\MetricDefinition;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateMetricDefinition extends CreateRecord
{
    protected static string $resource = MetricDefinitionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [MetricDefinition::class, $enterprise]);

        return $data;
    }
}
