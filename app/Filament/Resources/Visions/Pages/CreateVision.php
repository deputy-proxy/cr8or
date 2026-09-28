<?php

namespace App\Filament\Resources\Visions\Pages;

use App\Filament\Resources\Visions\VisionResource;
use App\Models\Enterprise;
use App\Services\StrategicContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVision extends CreateRecord
{
    protected static string $resource = VisionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);

        return app(StrategicContextService::class)->createVision(
            auth()->user(),
            $enterprise,
            (string) $data['statement'],
        );
    }
}