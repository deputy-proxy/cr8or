<?php

namespace App\Filament\Resources\Executions;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Executions\Pages\ListExecutions;
use App\Models\Execution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExecutionResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Execution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Executions';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('job.name')->label('Job')->searchable(),
            TextColumn::make('job.workflow.name')->label('Workflow')->searchable(),
            TextColumn::make('enterprise_name')->label('Enterprise')->searchable(),
            TextColumn::make('project_name')->label('Project')->searchable(),
            TextColumn::make('task_name')->label('Task')->searchable(),
            TextColumn::make('work_item_name')->label('Work item')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('started_at')->dateTime()->sortable(),
            TextColumn::make('completed_at')->dateTime()->sortable(),
            TextColumn::make('failure_reason')->limit(60),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListExecutions::route('/')];
    }
}