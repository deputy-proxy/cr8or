<?php

namespace App\Filament\Resources\Assignments\Pages;

use App\Filament\Resources\Assignments\AssignmentResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use Filament\Resources\Pages\EditRecord;

class EditAssignment extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = AssignmentResource::class;
}
