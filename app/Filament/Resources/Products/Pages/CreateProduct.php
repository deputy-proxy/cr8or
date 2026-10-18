<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        unset($data['enterprise_id']);

        return app(DomainResourceService::class)->createProduct($actor, $enterprise, $data);
    }
}
