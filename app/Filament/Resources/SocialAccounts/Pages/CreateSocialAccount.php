<?php

namespace App\Filament\Resources\SocialAccounts\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSocialAccount extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = SocialAccountResource::class;
}
