<?php

namespace App\Filament\Resources\ApprovalPolicies\Pages;

use App\Filament\Resources\ApprovalPolicies\ApprovalPolicyResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use Filament\Resources\Pages\CreateRecord;

class CreateApprovalPolicy extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = ApprovalPolicyResource::class;
}
