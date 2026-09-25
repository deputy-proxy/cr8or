<?php

namespace App\Filament\Resources\MediaMetadata;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\MediaMetadata\Pages\ListMediaMetadatas;
use App\Models\MediaMetadata;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MediaMetadataResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = MediaMetadata::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Media';

    protected static ?string $navigationLabel = 'Media Metadata';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('assetVersion.version')->searchable()->sortable(),
            TextColumn::make('width')->searchable()->sortable(),
            TextColumn::make('height')->searchable()->sortable(),
            TextColumn::make('duration_seconds')->searchable()->sortable(),
            TextColumn::make('codec')->searchable()->sortable(),
            TextColumn::make('frame_rate')->searchable()->sortable(),
        ])->recordActions([

        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('assetVersion.asset.enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
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
        return ['index' => ListMediaMetadatas::route('/')];
    }
}