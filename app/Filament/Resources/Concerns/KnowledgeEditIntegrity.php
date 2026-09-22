<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

trait KnowledgeEditIntegrity
{
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->record;
        if (! $record instanceof Model) {
            throw new AuthorizationException('Invalid knowledge record.');
        }

        if (isset($data['enterprise_id']) && (int) $data['enterprise_id'] !== (int) $record->getAttribute('enterprise_id')) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        return $data;
    }
}