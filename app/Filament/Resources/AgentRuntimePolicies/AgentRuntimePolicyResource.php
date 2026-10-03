<?php

namespace App\Filament\Resources\AgentRuntimePolicies;

use App\Filament\Resources\AgentRuntimePolicies\Pages\ListAgentRuntimePolicies;
use App\Models\AgentRuntimePolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentRuntimePolicyResource extends Resource
{
    protected static ?string $model = AgentRuntimePolicy::class;

    protected static ?string $modelLabel = 'Agent Runtime Policie';

    protected static ?string $pluralModelLabel = 'Agent Runtime Policies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?string $navigationLabel = 'Agent Runtime Policies';

    protected static ?int $navigationSort = 30;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('environment')->sortable(),
            TextColumn::make('organization_id')->label('Organization')->sortable(),
            TextColumn::make('enterprise_id')->label('Enterprise')->sortable(),
            TextColumn::make('agent_descriptor_id')->label('Agent')->sortable(),
            TextColumn::make('expert_descriptor_id')->label('Expert')->sortable(),
            TextColumn::make('enabled')->badge(),
            TextColumn::make('provider')->sortable(),
            TextColumn::make('max_steps')->sortable(),
            TextColumn::make('max_retries')->sortable(),
            TextColumn::make('timeout_seconds')->suffix('s'),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = auth()->user()?->memberships()->pluck('organization_id') ?? collect();

        return parent::getEloquentQuery()->whereIn('organization_id', $ids);
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('create', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListAgentRuntimePolicies::route('/')];
    }
}
