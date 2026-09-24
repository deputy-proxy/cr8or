<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Enterprise;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $parent = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [Product::class, $parent]);

        return $data;
    }
}