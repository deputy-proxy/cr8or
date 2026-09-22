<?php

namespace App\Filament\Resources\AgentPermissions\Pages;

use App\Filament\Resources\AgentPermissions\AgentPermissionResource;
use Filament\Resources\Pages\ListRecords;

class ListAgentPermissions extends ListRecords
{
    protected static string $resource = AgentPermissionResource::class;
}