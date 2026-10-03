<?php

namespace App\Filament\Resources\WorkflowVersions\Pages;

use App\Filament\Resources\WorkflowVersions\WorkflowVersionResource;
use Filament\Resources\Pages\ListRecords;

class ListWorkflowVersions extends ListRecords
{
    protected static string $resource = WorkflowVersionResource::class;
}
