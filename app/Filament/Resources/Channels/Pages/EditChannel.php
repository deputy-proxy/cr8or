<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditChannel extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
