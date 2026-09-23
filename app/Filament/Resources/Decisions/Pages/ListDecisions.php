<?php

namespace App\Filament\Resources\Decisions\Pages;

use App\Filament\Resources\Decisions\DecisionResource;
use Filament\Resources\Pages\ListRecords;

class ListDecisions extends ListRecords
{
    protected static string $resource = DecisionResource::class;
}