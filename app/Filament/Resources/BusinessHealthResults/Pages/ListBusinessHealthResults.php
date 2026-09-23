<?php

namespace App\Filament\Resources\BusinessHealthResults\Pages;

use App\Filament\Resources\BusinessHealthResults\BusinessHealthResultResource;
use Filament\Resources\Pages\ListRecords;

class ListBusinessHealthResults extends ListRecords
{
    protected static string $resource = BusinessHealthResultResource::class;
}