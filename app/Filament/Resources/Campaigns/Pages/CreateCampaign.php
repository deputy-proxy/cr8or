<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use Filament\Resources\Pages\CreateRecord;

class CreateCampaign extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = CampaignResource::class;
}
