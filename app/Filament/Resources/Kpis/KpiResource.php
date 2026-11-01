<?php

namespace App\Filament\Resources\Kpis;

use App\Enums\KpiMeasurementFrequency;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Kpis\Pages\CreateKpi;
use App\Filament\Resources\Kpis\Pages\EditKpi;
use App\Filament\Resources\Kpis\Pages\ListKpis;
use App\Filament\Resources\Kpis\RelationManagers\KpiMeasurementsRelationManager;
use App\Models\Kpi;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KpiResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Kpi::class;

    protected static ?string $modelLabel = 'KPI';

    protected static ?string $pluralModelLabel = 'KPIs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Strategy & Execution Planning';

    protected static ?string $navigationLabel = 'KPIs';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            ViewField::make('measurement_chart')
                ->view('filament.resources.kpis.measurement-chart')
                ->viewData(fn (?Kpi $record): array => [
                    'measurements' => $record?->measurements()
                        ->orderByDesc('measured_at')
                        ->orderByDesc('id')
                        ->limit(500)
                        ->get()
                        ->reverse()
                        ->values() ?? collect(),
                    'unit' => $record?->unit,
                ])
                ->columnSpanFull()
                ->visible(fn (string $operation): bool => $operation === 'edit'),
            Select::make('enterprise_id')
                ->relationship(
                    'enterprise',
                    'name',
                    modifyQueryUsing: fn (Builder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()),
                )
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('definition')->required()->maxLength(255),
            TextInput::make('unit')->maxLength(255),
            TextInput::make('target_value')->numeric(),
            Select::make('measurement_frequency')
                ->options(KpiMeasurementFrequency::options())
                ->nullable(),
            Select::make('status')
                ->options(['active' => 'Active', 'archived' => 'Archived'])
                ->default('active')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('current_value')->label('Current value')->numeric(decimalPlaces: 4),
            TextColumn::make('target_value')->label('Target')->numeric(decimalPlaces: 4),
            TextColumn::make('measurement_frequency')->badge(),
            TextColumn::make('status')->badge(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [KpiMeasurementsRelationManager::class];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListKpis::route('/'), 'create' => CreateKpi::route('/create'), 'edit' => EditKpi::route('/{record}/edit')];
    }
}
