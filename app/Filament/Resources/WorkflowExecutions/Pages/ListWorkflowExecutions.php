<?php

namespace App\Filament\Resources\WorkflowExecutions\Pages;

use App\Filament\Resources\WorkflowExecutions\WorkflowExecutionResource;
use Filament\Resources\Pages\ListRecords;

class ListWorkflowExecutions extends ListRecords
{
    protected static string $resource = WorkflowExecutionResource::class;
}
