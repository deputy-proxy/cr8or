<?php

namespace App\Filament\Resources\AgentExecutions;

use App\Filament\Resources\AgentExecutions\Pages\ListAgentExecutions;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\AgentExecution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentExecutionResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = AgentExecution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Agent Executions';

    protected static ?int $navigationSort = 40;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('agent_slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('organization_name')->label('Organization')->searchable()->sortable(),
            TextColumn::make('enterprise_name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('actor_name')->label('Actor')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('requested_at')->dateTime()->sortable(),
            TextColumn::make('completed_at')->dateTime()->sortable(),
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
        return ['index' => ListAgentExecutions::route('/')];
    }
}