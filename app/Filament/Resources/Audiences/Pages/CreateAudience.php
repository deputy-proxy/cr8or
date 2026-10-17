<?php

namespace App\Filament\Resources\Audiences\Pages;

use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordCreation;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\DomainResourceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;

class CreateAudience extends CreateRecord
{
    use AuthorizesScopedRecordCreation;

    protected static string $resource = AudienceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        $productIds = $data['product_ids'] ?? [];
        unset($data['enterprise_id'], $data['product_ids']);

        return app(DomainResourceService::class)->createAudience($actor, $enterprise, $data + ['product_ids' => $productIds]);
    }
}