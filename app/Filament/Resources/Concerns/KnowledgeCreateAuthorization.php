<?php

namespace App\Filament\Resources\Concerns;

use App\Models\Enterprise;
use Illuminate\Support\Facades\Gate;

trait KnowledgeCreateAuthorization
{
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $data['enterprise_id']);
        Gate::authorize('createForEnterprise', [$this->getResource()::getModel(), $enterprise]);

        return $data;
    }
}
