<?php

namespace App\Filament\Resources\Initiatives;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Initiatives\Pages\CreateInitiative;
use App\Filament\Resources\Initiatives\Pages\EditInitiative;
use App\Filament\Resources\Initiatives\Pages\ListInitiatives;
use App\Models\Initiative;
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

class InitiativeResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Initiative::class;

    protected static ?string $modelLabel = 'Initiative';

    protected static ?string $pluralModelLabel = 'Initiatives';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|\UnitEnum|null $navigationGroup = 'Enterprise Context';

    protected static ?string $navigationLabel = 'Initiatives';

    protected static ?int $navigationSort = 100;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('plan_id')->relationship('plan', 'name')->searchable()->preload()->required(),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('plan.name')->label('Plan')->searchable()->sortable(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('plan.strategy.objective.enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInitiatives::route('/'),
            'create' => CreateInitiative::route('/create'),
            'edit' => EditInitiative::route('/{record}/edit'),
        ];
    }
}
