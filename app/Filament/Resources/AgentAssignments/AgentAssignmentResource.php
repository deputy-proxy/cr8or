<?php

namespace App\Filament\Resources\AgentAssignments;

use App\Filament\Resources\AgentAssignments\Pages\CreateAgentAssignment;
use App\Filament\Resources\AgentAssignments\Pages\EditAgentAssignment;
use App\Filament\Resources\AgentAssignments\Pages\ListAgentAssignments;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\AgentAssignment;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentAssignmentResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = AgentAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|\UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Agent Assignments';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('agent_descriptor_id')->relationship('agentDescriptor', 'slug', fn (Builder $q) => $q->where('enabled', true))->searchable()->preload()->required(),
            Select::make('organization_id')->relationship('organization', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableOrganizationIds()))->searchable()->preload()->required(),
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('organization_id', static::manageableOrganizationIds()))->searchable()->preload(),
            Toggle::make('enabled')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('agentDescriptor.slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('organization.name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            IconColumn::make('enabled')->boolean(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index' => ListAgentAssignments::route('/'),
            'create' => CreateAgentAssignment::route('/create'),
            'edit' => EditAgentAssignment::route('/{record}/edit'),
        ];
    }
}
