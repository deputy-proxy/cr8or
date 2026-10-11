<?php

namespace App\Filament\Resources\Visions\Pages;

use App\Filament\Resources\Visions\VisionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVisions extends ListRecords
{
    protected static string $resource = VisionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}