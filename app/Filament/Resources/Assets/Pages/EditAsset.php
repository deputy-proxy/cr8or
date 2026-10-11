<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use Filament\Resources\Pages\EditRecord;

class EditAsset extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = AssetResource::class;
}
