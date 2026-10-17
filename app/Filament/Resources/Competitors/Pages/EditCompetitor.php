<?php

namespace App\Filament\Resources\Competitors\Pages;

use App\Filament\Resources\Competitors\CompetitorResource;
use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Models\Competitor;
use App\Services\StrategicContextService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCompetitor extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = CompetitorResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Competitor, 404);

        if ((int) $data['enterprise_id'] !== (int) $record->enterprise_id) {
            abort(422, 'A competitor cannot be reassigned to another enterprise.');
        }

        /** @var Competitor $successor */
        $successor = app(StrategicContextService::class)->reviseCompetitor(
            auth()->user(),
            $record,
            (string) $data['name'],
            isset($data['website']) ? (string) $data['website'] : null,
            isset($data['positioning']) ? (string) $data['positioning'] : null,
            array_values($data['strengths'] ?? []),
            array_values($data['weaknesses'] ?? []),
            array_intersect_key($data, array_flip(Competitor::PROFILE_FIELDS)),
        );

        $this->record = $successor;

        return $successor;
    }

    protected function getRedirectUrl(): string
    {
        return CompetitorResource::getUrl('view', ['record' => $this->record]);
    }
}
