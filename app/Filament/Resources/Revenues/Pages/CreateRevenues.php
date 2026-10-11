<?php

namespace App\Filament\Resources\Revenues\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Revenues\RevenueResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRevenues extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = RevenueResource::class;
}
