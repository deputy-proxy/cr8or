<?php

namespace App\Filament\Resources\ContentSeries\Pages;

use App\Filament\Resources\ContentSeries\ContentSeriesResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContentSeries extends EditRecord
{
    protected static string $resource = ContentSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
