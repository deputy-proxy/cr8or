<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = AssetResource::class;
}
