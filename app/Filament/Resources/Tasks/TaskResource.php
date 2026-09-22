<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Task;
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

class TaskResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('project_id')->relationship('project', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload(),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
            Select::make('status')->options(['todo' => 'To do', 'in_progress' => 'In progress', 'done' => 'Done'])->required(),
            Select::make('priority')->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('priority')->badge(),
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

    public static function canCreate(): bool
    {
        return auth()->check() && static::canManageAnyEnterprise();
    }

    public static function getPages(): array
    {
        return ['index' => ListTasks::route('/'), 'create' => CreateTask::route('/create'), 'edit' => EditTask::route('/{record}/edit')];
    }
}
