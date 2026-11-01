<?php

namespace App\Filament\Resources\Kpis\Pages;

use App\Filament\Resources\Concerns\AuthorizesScopedRecordUpdate;
use App\Filament\Resources\Kpis\KpiResource;
use App\Models\Kpi;
use App\Models\User;
use App\Operations\RecordKpiMeasurement;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditKpi extends EditRecord
{
    use AuthorizesScopedRecordUpdate;

    protected static string $resource = KpiResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Kpi $record */
        $record = $this->record;
        if ((int) $data['enterprise_id'] !== $record->enterprise_id) {
            throw new AuthorizationException('Cannot reassign this record.');
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordMeasurement')
                ->label('Record measurement')
                ->icon(\Filament\Support\Icons\Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->record instanceof Kpi && $this->record->status === 'active')
                ->form([
                    TextInput::make('value')->numeric()->required(),
                    DateTimePicker::make('measured_at')->default(now())->required(),
                    Textarea::make('notes')->rows(3)->maxLength(5000),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $kpi = $this->record;

                    if (! $actor instanceof User || ! $kpi instanceof Kpi) {
                        abort(403);
                    }

                    app(RecordKpiMeasurement::class)->execute($actor, [
                        'kpi_id' => $kpi->getKey(),
                        'value' => $data['value'],
                        'measured_at' => $data['measured_at'],
                        'source' => 'manual',
                        'notes' => $data['notes'] ?? null,
                    ]);

                    $kpi->refresh();
                    $this->refreshFormData(['measurement_chart']);
                    $this->dispatch('refreshKpiMeasurements');

                    Notification::make()
                        ->title('Measurement recorded')
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
