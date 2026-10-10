<?php

namespace App\Filament\Resources\MetricDefinitions;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\MetricDefinitions\Pages\CreateMetricDefinition;
use App\Filament\Resources\MetricDefinitions\Pages\EditMetricDefinition;
use App\Filament\Resources\MetricDefinitions\Pages\ListMetricDefinitions;
use App\Models\Enterprise;
use App\Models\MetricDefinition;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class MetricDefinitionResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = MetricDefinition::class;

    protected static ?string $modelLabel = 'Metric Definition';

    protected static ?string $pluralModelLabel = 'Metric Definitions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Reporting & Analytics';

    protected static ?string $navigationLabel = 'Metric Definitions';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->relationship(
                    'enterprise',
                    'name',
                    modifyQueryUsing: fn (Builder $query) => $query->whereIn('organization_id', static::manageableOrganizationIds()),
                )
                ->searchable()
                ->preload()
                ->required()
                ->disabledOn('edit'),
            TextInput::make('key')->required()->maxLength(255),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(3),
            TextInput::make('unit')->maxLength(100),
            Textarea::make('methodology')->rows(4),
            Select::make('status')
                ->options([
                    MetricDefinition::STATUS_ACTIVE => 'Active',
                    MetricDefinition::STATUS_ARCHIVED => 'Archived',
                ])
                ->default(MetricDefinition::STATUS_ACTIVE)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('key')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('unit')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('description')->limit(80),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn(
            'enterprise_id',
            Enterprise::query()
                ->select('id')
                ->whereIn('organization_id', static::authorizedOrganizationIds()),
        );
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetricDefinitions::route('/'),
            'create' => CreateMetricDefinition::route('/create'),
            'edit' => EditMetricDefinition::route('/{record}/edit'),
        ];
    }
}
