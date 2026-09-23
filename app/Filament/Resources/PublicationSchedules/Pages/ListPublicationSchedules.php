<?php

namespace App\Filament\Resources\PublicationSchedules\Pages;

use App\Filament\Resources\PublicationSchedules\PublicationScheduleResource;
use Filament\Resources\Pages\ListRecords;

class ListPublicationSchedules extends ListRecords
{
    protected static string $resource = PublicationScheduleResource::class;
}