<?php

namespace App\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIntegrationConnection extends CreateRecord
{
    protected static string $resource = IntegrationConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['configuration'] = IntegrationConnectionResource::builderStateToConfiguration(
            $data['configuration'] ?? [],
        );

        return $data;
    }
}
