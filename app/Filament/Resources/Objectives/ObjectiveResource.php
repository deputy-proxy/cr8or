<?php

namespace App\Filament\Resources\Objectives;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Objectives\Pages\CreateObjective;
use App\Filament\Resources\Objectives\Pages\EditObjective;
use App\Filament\Resources\Objectives\Pages\ListObjectives;
use App\Models\Objective;
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

class ObjectiveResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Objective::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('goal_id')->relationship('goal', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload(),
            Select::make('kpi_id')->relationship('kpi', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload(),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('goal.name')->label('Goal')->searchable(),
            TextColumn::make('kpi.name')->label('KPI')->searchable(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function canCreate(): bool
    {
        return auth()->check() && static::canManageAnyEnterprise();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListObjectives::route('/'),
            'create' => CreateObjective::route('/create'),
            'edit' => EditObjective::route('/{record}/edit'),
        ];
    }
}
