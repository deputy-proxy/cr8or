<?php

namespace App\Filament\Resources\EnterpriseCategories\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\EnterpriseCategories\EnterpriseCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditEnterpriseCategory extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = EnterpriseCategoryResource::class;
}
