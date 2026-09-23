<?php

namespace App\Filament\Resources\Transformations\Pages;

use App\Filament\Resources\Transformations\TransformationResource;
use Filament\Resources\Pages\ListRecords;

class ListTransformations extends ListRecords
{
    protected static string $resource = TransformationResource::class;
}