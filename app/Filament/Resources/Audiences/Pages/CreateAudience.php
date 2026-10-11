<?php

namespace App\Filament\Resources\Audiences\Pages;

use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use Filament\Resources\Pages\CreateRecord;

class CreateAudience extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = AudienceResource::class;
}
