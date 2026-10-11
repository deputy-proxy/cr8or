<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganization extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = OrganizationResource::class;
}
