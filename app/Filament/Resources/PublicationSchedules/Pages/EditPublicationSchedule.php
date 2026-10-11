<?php

namespace App\Filament\Resources\PublicationSchedules\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\PublicationSchedules\PublicationScheduleResource;
use Filament\Resources\Pages\EditRecord;

class EditPublicationSchedule extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = PublicationScheduleResource::class;
}
