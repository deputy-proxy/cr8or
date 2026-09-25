<?php

namespace App\Filament\Resources\Dependencies\Pages;

use App\Filament\Resources\Dependencies\DependencyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDependencies extends ListRecords
{
    protected static string $resource = DependencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}