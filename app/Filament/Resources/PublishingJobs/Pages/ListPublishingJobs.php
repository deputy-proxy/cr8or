<?php

namespace App\Filament\Resources\PublishingJobs\Pages;

use App\Filament\Resources\PublishingJobs\PublishingJobResource;
use Filament\Resources\Pages\ListRecords;

class ListPublishingJobs extends ListRecords
{
    protected static string $resource = PublishingJobResource::class;
}