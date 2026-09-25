<?php

namespace App\Filament\Resources\WorkItems;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\WorkItems\Pages\CreateWorkItem;
use App\Filament\Resources\WorkItems\Pages\EditWorkItem;
use App\Filament\Resources\WorkItems\Pages\ListWorkItems;
use App\Models\WorkItem;
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

class WorkItemResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = WorkItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|\UnitEnum|null $navigationGroup = 'Strategy';

    protected static ?string $navigationLabel = 'Work Items';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload()->required(),
            Select::make('project_id')->relationship('project', 'name')->searchable()->preload(),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
            Select::make('status')->options(['todo' => 'To do', 'in_progress' => 'In progress', 'done' => 'Done'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('project.name')->label('Project')->searchable()->sortable(), TextColumn::make('status')->badge()])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
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
        return ['index' => ListWorkItems::route('/'), 'create' => CreateWorkItem::route('/create'), 'edit' => EditWorkItem::route('/{record}/edit')];
    }
}
