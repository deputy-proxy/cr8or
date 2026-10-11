<?php

namespace App\Filament\Resources\Publications\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Publications\PublicationResource;
use Filament\Resources\Pages\EditRecord;

class EditPublication extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = PublicationResource::class;
}
