<?php

namespace App\Filament\Resources\AgentDescriptors\Pages;

use App\Filament\Resources\AgentDescriptors\AgentDescriptorResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use Filament\Resources\Pages\EditRecord;

class EditAgentDescriptor extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = AgentDescriptorResource::class;
}
