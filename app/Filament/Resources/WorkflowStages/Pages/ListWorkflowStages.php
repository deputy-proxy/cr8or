<?php

namespace App\Filament\Resources\WorkflowStages\Pages;

use App\Filament\Resources\WorkflowStages\WorkflowStageResource;
use Filament\Resources\Pages\ListRecords;

class ListWorkflowStages extends ListRecords
{
    protected static string $resource = WorkflowStageResource::class;
}
