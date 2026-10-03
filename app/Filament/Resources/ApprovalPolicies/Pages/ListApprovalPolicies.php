<?php

namespace App\Filament\Resources\ApprovalPolicies\Pages;

use App\Filament\Resources\ApprovalPolicies\ApprovalPolicyResource;
use Filament\Resources\Pages\ListRecords;

class ListApprovalPolicies extends ListRecords
{
    protected static string $resource = ApprovalPolicyResource::class;
}
