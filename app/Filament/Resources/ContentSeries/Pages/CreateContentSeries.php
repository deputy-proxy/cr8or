<?php

namespace App\Filament\Resources\ContentSeries\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\ContentSeries\ContentSeriesResource;
use App\Models\Campaign;
use App\Models\ContentSeries;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateContentSeries extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $campaign = Campaign::query()->findOrFail((int) $data['campaign_id']);
        Gate::authorize('createForCampaign', [ContentSeries::class, $campaign]);

        return $data;
    }

    protected static string $resource = ContentSeriesResource::class;
}
