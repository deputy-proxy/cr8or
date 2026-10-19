<?php

namespace App\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use Filament\Resources\Pages\EditRecord;

class EditIntegrationConnection extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = IntegrationConnectionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['configuration'] = IntegrationConnectionResource::configurationToBuilderState(
            is_array($data['configuration'] ?? null) ? $data['configuration'] : null,
        );

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['configuration'] = IntegrationConnectionResource::builderStateToConfiguration(
            $data['configuration'] ?? [],
        );

        if (! app(\App\Services\IntegrationRegistry::class)->isMediaProvider($data['provider'] ?? null)) {
            $data['oauth_client_id'] = null;
        }

        return $data;
    }
}