<?php

namespace App\Filament\Resources\AgentPermissions;

use App\Filament\Resources\AgentPermissions\Pages\CreateAgentPermission;
use App\Filament\Resources\AgentPermissions\Pages\EditAgentPermission;
use App\Filament\Resources\AgentPermissions\Pages\ListAgentPermissions;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\AgentPermission;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AgentPermissionResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = AgentPermission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Agent Permissions';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('agent_assignment_id')->relationship('agentAssignment', 'id', fn (Builder $q) => $q->whereIn('organization_id', static::manageableOrganizationIds()))->searchable()->preload()->required(),
            TextInput::make('capability')->required()->maxLength(255),
            Toggle::make('requires_approval')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('agentAssignment.agentDescriptor.slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('agentAssignment.organization.name')->label('Organization')->searchable()->sortable(),
            TextColumn::make('agentAssignment.enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('capability')->searchable()->sortable(),
            IconColumn::make('requires_approval')->boolean(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('agentAssignment', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentPermissions::route('/'),
            'create' => CreateAgentPermission::route('/create'),
            'edit' => EditAgentPermission::route('/{record}/edit'),
        ];
    }
}
