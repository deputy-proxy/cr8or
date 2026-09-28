<?php

namespace App\Filament\Resources\Missions\Pages;

use App\Filament\Resources\Missions\MissionResource;
use App\Models\Enterprise;
use App\Services\StrategicContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMission extends CreateRecord
{
    protected static string $resource = MissionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);

        return app(StrategicContextService::class)->createMission(
            auth()->user(),
            $enterprise,
            (string) $data['statement'],
        );
    }
}