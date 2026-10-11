<?php

namespace App\Filament\Resources\Dependencies\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Dependencies\DependencyResource;
use Filament\Resources\Pages\EditRecord;

class EditDependency extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = DependencyResource::class;
}
