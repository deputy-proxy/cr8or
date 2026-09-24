<?php

namespace App\Filament\Resources\AgentDelegations;

use App\Filament\Resources\AgentDelegations\Pages\ListAgentDelegations;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\AgentDelegation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentDelegationResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = AgentDelegation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('source_agent_slug')->label('Source Agent')->searchable()->sortable(),
            TextColumn::make('target_agent_slug')->label('Target Agent')->searchable()->sortable(),
            TextColumn::make('organization_name')->label('Organization')->searchable()->sortable(),
            TextColumn::make('enterprise_name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('actor_name')->label('Actor')->searchable(),
            TextColumn::make('capability')->searchable()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('attempts')->sortable(),
            TextColumn::make('requested_at')->dateTime()->sortable(),
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
        return ['index' => ListAgentDelegations::route('/')];
    }
}
