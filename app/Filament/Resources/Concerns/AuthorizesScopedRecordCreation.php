<?php

namespace App\Filament\Resources\Concerns;

use App\Models\Workflow;
use App\Policies\Support\RecordOwnership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

trait AuthorizesScopedRecordCreation
{
    protected function beforeCreate(): void
    {
        $modelClass = $this->getResource()::getModel();
        $record = new $modelClass($this->form->getState());

        if (! $record instanceof Model) {
            throw new AuthorizationException('The resource model must be an Eloquent model.');
        }

        // Generic workflows are reusable platform definitions. Their own policy
        // controls who may create them; they are not assigned to an enterprise.
        if ($record instanceof Workflow
            && ! $record->isEnterpriseSpecific()
            && $record->enterprise_id === null) {
            Gate::authorize('create', $modelClass);

            return;
        }

        $ownership = app(RecordOwnership::class);

        if (! $ownership->hasOwnershipBoundary($record)) {
            Gate::authorize('create', $modelClass);

            return;
        }

        $policy = Gate::getPolicyFor($modelClass);
        $enterprise = $ownership->enterpriseForRecord($record);

        if ($record->getAttribute('enterprise_id') !== null && $enterprise === null) {
            throw new AuthorizationException('The submitted enterprise does not have a valid ownership context.');
        }

        $modelAbility = 'createFor'.class_basename($modelClass);

        if ($policy !== null && method_exists($policy, $modelAbility)) {
            Gate::authorize($modelAbility, [$modelClass, $record]);

            return;
        }

        $parent = $ownership->parentForRecord($record);
        $parentAbility = $parent instanceof Model ? 'createFor'.class_basename($parent) : null;

        if ($policy !== null && $parentAbility !== null && method_exists($policy, $parentAbility)) {
            Gate::authorize($parentAbility, [$modelClass, $parent]);

            return;
        }

        if ($enterprise !== null) {
            if ($policy !== null && method_exists($policy, 'createForEnterprise')) {
                Gate::authorize('createForEnterprise', [$modelClass, $enterprise]);

                return;
            }

            if ($policy !== null && method_exists($policy, 'createForOrganization')) {
                Gate::authorize('createForOrganization', [$modelClass, $enterprise->organization]);

                return;
            }

            $this->authorizeScopedRecord($modelClass, $record);

            return;
        }

        $organization = $ownership->organizationForRecord($record);

        if ($organization !== null) {
            if ($policy !== null && method_exists($policy, 'createForOrganization')) {
                Gate::authorize('createForOrganization', [$modelClass, $organization]);

                return;
            }

            $this->authorizeScopedRecord($modelClass, $record);

            return;
        }

        if ($policy !== null && method_exists($policy, 'createForRecord')) {
            Gate::authorize('createForRecord', [$modelClass, $record]);

            return;
        }

        throw new AuthorizationException('The record creation target has no verifiable authorized ownership context.');
    }

    private function authorizeScopedRecord(string $modelClass, Model $record): void
    {
        $policy = Gate::getPolicyFor($modelClass);

        if ($policy !== null && method_exists($policy, 'createForRecord')) {
            Gate::authorize('createForRecord', [$modelClass, $record]);

            return;
        }

        throw new AuthorizationException('The model policy must define a contextual create ability for this ownership boundary.');
    }
}
