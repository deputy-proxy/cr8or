<?php

namespace App\Filament\Resources\RenderOutputs;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\RenderOutputs\Pages\ListRenderOutputs;
use App\Models\RenderOutput;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RenderOutputResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = RenderOutput::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Media';

    protected static ?string $navigationLabel = 'Render Outputs';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('request.id')->searchable()->sortable(),
            TextColumn::make('assetVersion.version')->searchable()->sortable(),
            TextColumn::make('external_output_id')->searchable()->sortable(),
            TextColumn::make('disk')->searchable()->sortable(),
            TextColumn::make('path')->searchable()->sortable(),
            TextColumn::make('mime_type')->searchable()->sortable(),
            TextColumn::make('size')->searchable()->sortable(),
            TextColumn::make('checksum')->searchable()->sortable(),
        ])->recordActions([

        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('request.enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
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
        return ['index' => ListRenderOutputs::route('/')];
    }
}