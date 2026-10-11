<?php

namespace App\Filament\Resources\EnterpriseGroups\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\EnterpriseGroups\EnterpriseGroupResource;
use Filament\Resources\Pages\EditRecord;

class EditEnterpriseGroup extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = EnterpriseGroupResource::class;
}
