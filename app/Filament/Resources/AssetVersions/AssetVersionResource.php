<?php

namespace App\Filament\Resources\AssetVersions;

use App\Filament\Resources\AssetVersions\Pages\ListAssetVersions;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\AssetVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AssetVersionResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = AssetVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('asset.name')->searchable()->sortable(),
            TextColumn::make('version')->searchable()->sortable(),
            TextColumn::make('disk')->searchable()->sortable(),
            TextColumn::make('path')->searchable()->sortable(),
            TextColumn::make('mime_type')->searchable()->sortable(),
            TextColumn::make('size')->searchable()->sortable(),
            TextColumn::make('checksum')->searchable()->sortable(),
            TextColumn::make('external_reference')->searchable()->sortable(),
        ])->recordActions([

        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('asset.enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
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
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListAssetVersions::route('/')];
    }
}
