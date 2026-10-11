<?php

namespace App\Filament\Resources\Publications\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Publications\PublicationResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePublication extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = PublicationResource::class;
}
