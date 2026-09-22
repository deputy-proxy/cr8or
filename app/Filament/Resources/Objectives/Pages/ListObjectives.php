<?php

namespace App\Filament\Resources\Objectives\Pages;

use App\Filament\Resources\Objectives\ObjectiveResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListObjectives extends ListRecords
{
    protected static string $resource = ObjectiveResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
