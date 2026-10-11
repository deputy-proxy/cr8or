<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use Filament\Resources\Pages\CreateRecord;

class CreateChannel extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = ChannelResource::class;
}
