<?php

namespace App\Filament\Resources\ContentSeries\Pages;

use App\Filament\Resources\ContentSeries\ContentSeriesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContentSeries extends ListRecords
{
    protected static string $resource = ContentSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}