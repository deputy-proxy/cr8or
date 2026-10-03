<?php

namespace App\Filament\Resources\MetricDefinitions\Pages;

use App\Filament\Resources\MetricDefinitions\MetricDefinitionResource;
use Filament\Resources\Pages\ListRecords;

class ListMetricDefinitions extends ListRecords
{
    protected static string $resource = MetricDefinitionResource::class;
}
