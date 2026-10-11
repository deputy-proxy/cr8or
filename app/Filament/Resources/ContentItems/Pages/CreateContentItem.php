<?php

namespace App\Filament\Resources\ContentItems\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\ContentItems\ContentItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContentItem extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = ContentItemResource::class;
}
