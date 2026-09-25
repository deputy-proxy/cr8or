<?php

namespace App\Filament\Resources\Assets;

use App\Filament\Resources\Assets\Pages\CreateAsset;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\Asset;
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

class AssetResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Media';

    protected static ?string $navigationLabel = 'Assets';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('content_item_id')->relationship('contentItem', 'title', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            TextInput::make('name')->maxLength(255),
            TextInput::make('type')->maxLength(255),
            Select::make('status')->options(['active' => 'Active', 'archived' => 'Archived'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('contentItem.title')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('type')->badge()->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
        ])->recordActions([
            EditAction::make(), DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('enterprise_id', static::authorizedEnterpriseIds());
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

    public static function canCreate(): bool
    {
        return auth()->check() && static::canManageAnyEnterprise();
    }

    public static function getPages(): array
    {
        return ['index' => ListAssets::route('/'), 'create' => CreateAsset::route('/create'), 'edit' => EditAsset::route('/{record}/edit')];
    }
}