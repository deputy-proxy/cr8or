<?php

namespace App\Filament\Resources\AgentDecisions;

use App\Filament\Resources\AgentDecisions\Pages\ListAgentDecisions;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\AgentDecision;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentDecisionResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = AgentDecision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('agent_slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('organization_name')->label('Organization')->searchable()->sortable(),
            TextColumn::make('enterprise_name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('actor_name')->label('Actor')->searchable(),
            TextColumn::make('decided_at')->dateTime()->sortable(),
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
        return ['index' => ListAgentDecisions::route('/')];
    }
}