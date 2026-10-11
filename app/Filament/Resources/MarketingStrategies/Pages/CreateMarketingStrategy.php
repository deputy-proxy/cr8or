<?php

namespace App\Filament\Resources\MarketingStrategies\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Concerns\EnterpriseCreateAuthorization;
use App\Filament\Resources\MarketingStrategies\MarketingStrategyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketingStrategy extends CreateRecord
{
    use AuthorizesScopedRecordCreation;
    use EnterpriseCreateAuthorization;

    protected static string $resource = MarketingStrategyResource::class;
}
