<?php

namespace App\Filament\Resources\IntegrationConnections\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIntegrationConnection extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = IntegrationConnectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['configuration'] = IntegrationConnectionResource::builderStateToConfiguration(
            $data['configuration'] ?? [],
        );

        // Status is read-only in the form; initialize new connections on the server.
        $data['status'] = \App\Models\IntegrationConnection::STATUS_ACTIVE;

        if (! app(\App\Services\IntegrationRegistry::class)->isMediaProvider($data['provider'] ?? null)) {
            $data['oauth_client_id'] = null;
        }

        return $data;
    }
}