<?php

namespace App\Filament\Resources\ApprovalPolicies\Pages;

use App\Filament\Resources\ApprovalPolicies\ApprovalPolicyResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use Filament\Resources\Pages\EditRecord;

class EditApprovalPolicy extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = ApprovalPolicyResource::class;
}
