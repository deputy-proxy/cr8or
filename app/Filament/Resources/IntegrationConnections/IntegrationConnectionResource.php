<?php

namespace App\Filament\Resources\IntegrationConnections;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\IntegrationConnections\Pages\CreateIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\Pages\EditIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\Pages\ListIntegrationConnections;
use App\Models\IntegrationConnection;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IntegrationConnectionResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = IntegrationConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'Connections';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')->relationship('organization', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableOrganizationIds()))->searchable()->preload()->required(),
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            TextInput::make('provider')->maxLength(255),
            TextInput::make('external_account_id')->maxLength(255),
            TextInput::make('credential_reference')->required()->maxLength(255),
            Select::make('status')->options(['active' => 'Active', 'disabled' => 'Disabled'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('organization.name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('provider')->badge()->searchable()->sortable(),
            TextColumn::make('external_account_id')->searchable()->sortable(),
            TextColumn::make('credential_reference')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
        ])->recordActions([
            EditAction::make(), DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    /** @return Builder<\App\Models\Enterprise> */
    protected static function authorizedEnterpriseIds(): Builder
    {
        return \App\Models\Enterprise::query()->select('enterprises.id')->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function getPages(): array
    {
        return ['index' => ListIntegrationConnections::route('/'), 'create' => CreateIntegrationConnection::route('/create'), 'edit' => EditIntegrationConnection::route('/{record}/edit')];
    }
}
