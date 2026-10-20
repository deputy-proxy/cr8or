<?php

namespace App\Filament\Resources\Personas\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Personas\PersonaResource;
use App\Models\Persona;
use App\Models\User;
use App\Services\DomainResourceService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;

class EditPersona extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = PersonaResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Persona $record */
        $record = $this->record;
        $data['product_ids'] = $record->products()->pluck('products.id')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Persona) {
            throw new \LogicException('Unexpected persona record.');
        }

        if (isset($data['enterprise_id']) && (int) $data['enterprise_id'] !== (int) $record->enterprise_id) {
            throw new AuthorizationException('Persona enterprise ownership cannot be changed.');
        }

        $actor = auth()->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        $productIds = $data['product_ids'] ?? [];
        unset($data['enterprise_id'], $data['product_ids']);

        return app(DomainResourceService::class)->updatePersona($actor, $record, $data + ['product_ids' => $productIds]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}