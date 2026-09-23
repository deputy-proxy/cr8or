<?php

namespace App\Filament\Resources\AssetVersions\Pages;

use App\Filament\Resources\AssetVersions\AssetVersionResource;
use Filament\Resources\Pages\ListRecords;

class ListAssetVersions extends ListRecords
{
    protected static string $resource = AssetVersionResource::class;
}