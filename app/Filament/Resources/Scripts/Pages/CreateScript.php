<?php

namespace App\Filament\Resources\Scripts\Pages;

use App\Filament\Resources\Scripts\ScriptResource;
use App\Models\ContentItem;
use App\Models\Script;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateScript extends CreateRecord
{
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $item = ContentItem::query()->findOrFail((int) $data['content_item_id']);
        Gate::authorize('createForContentItem', [Script::class, $item]);

        return $data;
    }

    protected static string $resource = ScriptResource::class;
}
