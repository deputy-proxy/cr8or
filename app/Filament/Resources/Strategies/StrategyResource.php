<?php

namespace App\Filament\Resources\Strategies;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Strategies\Pages\CreateStrategy;
use App\Filament\Resources\Strategies\Pages\EditStrategy;
use App\Filament\Resources\Strategies\Pages\ListStrategies;
use App\Models\Strategy;
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

class StrategyResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Strategy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|\UnitEnum|null $navigationGroup = 'Enterprise Context';

    protected static ?string $navigationLabel = 'Strategies';

    protected static ?int $navigationSort = 80;


    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('objective_id')->relationship('objective', 'name')->searchable()->preload()->required(),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('objective.name')->label('Objective')->searchable()->sortable(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('objective.enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStrategies::route('/'),
            'create' => CreateStrategy::route('/create'),
            'edit' => EditStrategy::route('/{record}/edit'),
        ];
    }
}
