<?php

namespace App\Filament\Resources\Competitors\Pages;

use App\Filament\Resources\Competitors\CompetitorResource;
use App\Models\Enterprise;
use App\Services\StrategicContextService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCompetitor extends CreateRecord
{
    protected static string $resource = CompetitorResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);

        return app(StrategicContextService::class)->createCompetitor(
            auth()->user(),
            $enterprise,
            (string) $data['name'],
            isset($data['website']) ? (string) $data['website'] : null,
            isset($data['positioning']) ? (string) $data['positioning'] : null,
            array_values($data['strengths'] ?? []),
            array_values($data['weaknesses'] ?? []),
        );
    }
}