<?php

namespace App\Filament\Resources\Projects;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Project;
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

class ProjectResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|\UnitEnum|null $navigationGroup = 'Strategy';

    protected static ?string $navigationLabel = 'Projects';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('strategy_id')->relationship('strategy', 'name', fn (Builder $q) => $q->whereHas('objective.enterprise', fn (Builder $e) => $e->whereIn('id', static::manageableEnterpriseIds())))->searchable()->preload(),
            Select::make('plan_id')->relationship('plan', 'name', fn (Builder $q) => $q->whereHas('strategy.objective.enterprise', fn (Builder $e) => $e->whereIn('id', static::manageableEnterpriseIds())))->searchable()->preload(),
            Select::make('initiative_id')->relationship('initiative', 'name', fn (Builder $q) => $q->whereHas('plan.strategy.objective.enterprise', fn (Builder $e) => $e->whereIn('id', static::manageableEnterpriseIds())))->searchable()->preload(),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
            Select::make('status')->options(['planned' => 'Planned', 'active' => 'Active', 'completed' => 'Completed'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('status')->badge(),
        ])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function getPages(): array
    {
        return ['index' => ListProjects::route('/'), 'create' => CreateProject::route('/create'), 'edit' => EditProject::route('/{record}/edit')];
    }
}
