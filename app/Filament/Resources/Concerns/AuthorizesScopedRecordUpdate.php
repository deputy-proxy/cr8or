<?php

namespace App\Filament\Resources\Concerns;

use App\Policies\Support\RecordOwnership;
use Illuminate\Support\Facades\Gate;

trait AuthorizesScopedRecordUpdate
{
    protected function beforeSave(): void
    {
        $modelClass = $this->getResource()::getModel();
        $record = $this->getRecord();
        $target = clone $record;
        $target->forceFill($this->form->getState());
        $target->unsetRelations();

        $ownership = app(RecordOwnership::class);

        if (! $ownership->hasOwnershipBoundary($target)) {
            return;
        }

        $policy = Gate::getPolicyFor($modelClass);

        if ($policy !== null && method_exists($policy, 'updateForRecord')) {
            Gate::authorize('updateForRecord', [$modelClass, $target]);
        }

        Gate::authorize('update', $target);
    }
}
