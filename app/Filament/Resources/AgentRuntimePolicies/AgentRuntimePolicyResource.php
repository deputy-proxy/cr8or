<?php

namespace App\Filament\Resources\AgentRuntimePolicies;

use App\Filament\Resources\AgentRuntimePolicies\Pages\CreateAgentRuntimePolicy;
use App\Filament\Resources\AgentRuntimePolicies\Pages\EditAgentRuntimePolicy;
use App\Filament\Resources\AgentRuntimePolicies\Pages\ListAgentRuntimePolicies;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Models\AgentRuntimePolicy;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentRuntimePolicyResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = AgentRuntimePolicy::class;

    protected static ?string $modelLabel = 'Agent Runtime Policy';

    protected static ?string $pluralModelLabel = 'Agent Runtime Policies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Agent Governance & Operations';

    protected static ?string $navigationLabel = 'Agent Runtime Policies';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('environment')->required()->maxLength(100),
            Select::make('organization_id')->relationship('organization', 'name')->searchable()->preload()->required(),
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload(),
            Select::make('agent_descriptor_id')->relationship('agentDescriptor', 'slug')->searchable()->preload(),
            Select::make('expert_descriptor_id')->relationship('expertDescriptor', 'slug')->searchable()->preload(),
            Toggle::make('enabled')->default(true),
            TextInput::make('provider')->maxLength(100),
            TextInput::make('model')->maxLength(255),
            TagsInput::make('fallback_providers'),
            TextInput::make('max_steps')->numeric()->minValue(1),
            TextInput::make('max_retries')->numeric()->minValue(1),
            TextInput::make('timeout_seconds')->numeric()->minValue(1),
            TextInput::make('max_context_bytes')->numeric()->minValue(1),
            TextInput::make('retrieved_knowledge_limit')->numeric()->minValue(1),
            TextInput::make('memory_limit')->numeric()->minValue(1),
        ]);
    }

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
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopeAuthorizedOwnership(parent::getEloquentQuery());
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
        return [
            'index' => ListAgentRuntimePolicies::route('/'),
            'create' => CreateAgentRuntimePolicy::route('/create'),
            'edit' => EditAgentRuntimePolicy::route('/{record}/edit'),
        ];
    }
}
