<?php

namespace App\Filament\Resources\PublicationSchedules\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\PublicationSchedules\PublicationScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePublicationSchedule extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = PublicationScheduleResource::class;
}
