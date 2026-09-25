<?php

namespace App\Filament\Resources\Enterprises;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Enterprises\Pages\CreateEnterprise;
use App\Filament\Resources\Enterprises\Pages\EditEnterprise;
use App\Filament\Resources\Enterprises\Pages\ListEnterprises;
use App\Models\Enterprise;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnterpriseResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Enterprise::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|\UnitEnum|null $navigationGroup = 'Organization';

    protected static ?string $navigationLabel = 'Enterprises';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('organization_id')->relationship('organization', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableOrganizationIds()))->searchable()->preload()->required(), TextInput::make('name')->required()->maxLength(255), TextInput::make('slug')->required()->maxLength(255), Select::make('status')->options(['active' => 'Active', 'archived' => 'Archived'])->default('active')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('organization.name')->searchable()->sortable(), TextColumn::make('status')->badge()])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
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
        return static::canCreateForCurrentUser(static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListEnterprises::route('/'), 'create' => CreateEnterprise::route('/create'), 'edit' => EditEnterprise::route('/{record}/edit')];
    }
}
