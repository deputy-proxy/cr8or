<?php

namespace App\Filament\Resources\Tasks;

use App\Enums\TaskStatus;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Project;
use App\Models\Task;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Strategy & Execution Planning';

    protected static ?string $navigationLabel = 'Tasks';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 4])
                ->schema([
                    Section::make('Task details')
                        ->columns(1)
                        ->schema([
                            TextInput::make('name')->required()->maxLength(255),
                            Textarea::make('description')->rows(4),
                        ])
                        ->columnSpan(['default' => 1, 'lg' => 3]),

                    Section::make('Organization & scheduling')
                        ->columns(1)
                        ->schema([
                            Select::make('enterprise_id')
                                ->label('Enterprise')
                                ->options(fn (): array => Enterprise::query()
                                    ->whereIn('id', static::manageableEnterpriseIds())
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('project_id', null);
                                    $set('initiative_id', null);
                                    $set('parent_task_id', null);
                                }),
                            Select::make('project_id')
                                ->label('Project')
                                ->options(fn (Get $get): array => $get('enterprise_id')
                                    ? Project::query()
                                        ->where('enterprise_id', $get('enterprise_id'))
                                        ->whereIn('enterprise_id', static::manageableEnterpriseIds())
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all()
                                    : [])
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    if (filled($state)) {
                                        $set('initiative_id', null);
                                    }
                                    $set('parent_task_id', null);
                                })
                                ->searchable()
                                ->live(),
                            Select::make('initiative_id')
                                ->label('Initiative')
                                ->options(fn (Get $get): array => $get('enterprise_id')
                                    ? Initiative::query()
                                        ->where('enterprise_id', $get('enterprise_id'))
                                        ->whereIn('enterprise_id', static::manageableEnterpriseIds())
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all()
                                    : [])
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    if (filled($state)) {
                                        $set('project_id', null);
                                    }
                                    $set('parent_task_id', null);
                                })
                                ->searchable()
                                ->live(),
                            Select::make('parent_task_id')
                                ->label('Parent Task')
                                ->options(fn (Get $get): array => $get('enterprise_id')
                                    ? Task::query()
                                        ->where('enterprise_id', $get('enterprise_id'))
                                        ->whereIn('enterprise_id', static::manageableEnterpriseIds())
                                        ->when(filled($get('project_id')), fn (Builder $query) => $query->where('project_id', $get('project_id')))
                                        ->when(filled($get('initiative_id')), fn (Builder $query) => $query->where('initiative_id', $get('initiative_id')))
                                        ->when(blank($get('project_id')) && blank($get('initiative_id')), fn (Builder $query) => $query->whereNull('project_id')->whereNull('initiative_id'))
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all()
                                    : [])
                                ->searchable()
                                ->placeholder('No parent Task'),
                            Select::make('status')->options(TaskStatus::options())->required(),
                            Select::make('priority')->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'])->required(),
                            DateTimePicker::make('due_at')->label('Due At'),
                        ])
                        ->columnSpan(['default' => 1, 'lg' => 1]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
            TextColumn::make('initiative.name')->label('Initiative')->searchable()->sortable(),
            TextColumn::make('parent.name')->label('Parent Task')->toggleable(),
            SelectColumn::make('status')
                ->options(TaskStatus::options())
                ->rules(['required', 'in:'.implode(',', TaskStatus::values())])
                ->selectablePlaceholder(false)
                ->disabled(fn (Task $record): bool => Gate::denies('update', $record))
                ->beforeStateUpdated(fn (Task $record) => Gate::authorize('update', $record)),
            SelectColumn::make('priority')
                ->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'])
                ->rules(['required', 'in:low,normal,high'])
                ->selectablePlaceholder(false)
                ->disabled(fn (Task $record): bool => Gate::denies('update', $record))
                ->beforeStateUpdated(fn (Task $record) => Gate::authorize('update', $record)),
            TextColumn::make('due_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('project_id')
                ->label('Project')
                ->options(fn (): array => Project::query()
                    ->whereIn('enterprise_id', static::authorizedEnterpriseIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),
            SelectFilter::make('initiative_id')
                ->label('Initiative')
                ->options(fn (): array => Initiative::query()
                    ->whereIn('enterprise_id', static::authorizedEnterpriseIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),
            SelectFilter::make('status')->options(TaskStatus::options()),
            SelectFilter::make('priority')->options([
                'low' => 'Low',
                'normal' => 'Normal',
                'high' => 'High',
            ]),
        ])->groups([
            Group::make('enterprise_id')
                ->label('Enterprise')
                ->getKeyFromRecordUsing(fn (Task $record): int => $record->enterprise_id)
                ->getTitleFromRecordUsing(fn (Task $record): string => $record->enterprise->name)
                ->titlePrefixedWithLabel(false)
                ->collapsible(),
            Group::make('project_id')
                ->label('Project')
                ->getKeyFromRecordUsing(fn (Task $record): int|string => $record->project_id ?? 'unassigned')
                ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key): Builder => $key === 'unassigned' ? $query->whereNull('project_id') : $query->where('project_id', $key))
                ->getTitleFromRecordUsing(fn (Task $record): string => $record->project_id === null ? 'No Project' : $record->project->name)
                ->titlePrefixedWithLabel(false)
                ->collapsible(),
            Group::make('initiative_id')
                ->label('Initiative')
                ->getKeyFromRecordUsing(fn (Task $record): int|string => $record->initiative_id ?? 'unassigned')
                ->scopeQueryByKeyUsing(fn (Builder $query, ?string $key): Builder => $key === 'unassigned' ? $query->whereNull('initiative_id') : $query->where('initiative_id', $key))
                ->getTitleFromRecordUsing(fn (Task $record): string => $record->initiative_id === null ? 'No Initiative' : $record->initiative->name)
                ->titlePrefixedWithLabel(false)
                ->collapsible(),
            Group::make('status')
                ->label('Status')
                ->getTitleFromRecordUsing(fn (Task $record): string => $record->status->label())
                ->titlePrefixedWithLabel(false)
                ->collapsible(),
        ])->defaultGroup('enterprise_id')->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
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
