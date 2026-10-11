<?php

namespace App\Filament\Resources\Revenues\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Revenues\RevenueResource;
use Filament\Resources\Pages\EditRecord;

class EditRevenues extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = RevenueResource::class;
}
