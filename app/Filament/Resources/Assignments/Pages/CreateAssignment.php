<?php

namespace App\Filament\Resources\Assignments\Pages;

use App\Filament\Resources\Assignments\AssignmentResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use Filament\Resources\Pages\CreateRecord;

class CreateAssignment extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = AssignmentResource::class;
}
