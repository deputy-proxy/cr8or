<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Enterprise;
use App\Models\Project;
use App\Models\Task;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class TaskResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Task::class;

    protected static ?string $modelLabel = 'Task';

    protected static ?string $pluralModelLabel = 'Tasks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Work Management';

    protected static ?string $navigationLabel = 'Tasks';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->options(fn (): array => Enterprise::query()
                    ->whereIn('id', static::manageableEnterpriseIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->live(),
            Select::make('project_id')
                ->options(fn (Get $get): array => $get('enterprise_id')
                    ? Project::query()
                        ->where('enterprise_id', $get('enterprise_id'))
                        ->whereIn('enterprise_id', static::manageableEnterpriseIds())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()
                    : [])
                ->searchable()
                ->live(),
            Select::make('parent_task_id')
                ->options(fn (Get $get): array => $get('enterprise_id')
                    ? Task::query()
                        ->where('enterprise_id', $get('enterprise_id'))
                        ->whereIn('enterprise_id', static::manageableEnterpriseIds())
                        ->when($get('project_id') === null || $get('project_id') === '', fn (Builder $query) => $query->whereNull('project_id'), fn (Builder $query) => $query->where('project_id', $get('project_id')))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()
                    : [])
                ->searchable()
                ->placeholder('No parent Task'),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
            Select::make('status')->options(function (?Task $record): array {
                $options = ['todo' => 'To do', 'in_progress' => 'In progress', 'done' => 'Done'];
                if ($record !== null && ! array_key_exists($record->status, $options)) {
                    $options[$record->status] = $record->status;
                }

                return $options;
            })->required(),
            Select::make('priority')->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'])->required(),
            DateTimePicker::make('due_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
            TextColumn::make('parent.name')->label('Parent Task')->toggleable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('priority')->badge(),
            TextColumn::make('due_at')->dateTime()->sortable(),
        ])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('enterprise', fn (Builder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListTasks::route('/'), 'create' => CreateTask::route('/create'), 'edit' => EditTask::route('/{record}/edit')];
    }
}
