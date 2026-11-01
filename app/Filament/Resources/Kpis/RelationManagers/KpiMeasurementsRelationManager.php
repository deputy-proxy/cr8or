<?php

namespace App\Filament\Resources\Kpis\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class KpiMeasurementsRelationManager extends RelationManager
{
    protected static string $relationship = 'measurements';

    protected static ?string $title = 'Measurement history';

    #[On('refreshKpiMeasurements')]
    public function refreshMeasurements(): void {}

    public function table(Table $table): Table
    {
        return $table->defaultSort('measured_at', 'desc')->columns([
            TextColumn::make('measured_at')->dateTime()->sortable(),
            TextColumn::make('value')->numeric(decimalPlaces: 4),
            TextColumn::make('source')->badge(),
            TextColumn::make('notes')->limit(80)->tooltip(fn ($state): ?string => is_string($state) ? $state : null),
            TextColumn::make('recordedBy.name')->label('Recorded by')->placeholder('System'),
        ]);
    }
}
