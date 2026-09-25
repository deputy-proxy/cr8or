<?php

namespace App\Filament\Resources\PublicationResults;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\PublicationResults\Pages\ListPublicationResults;
use App\Models\PublicationResult;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PublicationResultResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = PublicationResult::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?string $navigationLabel = 'Publication Results';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('publication.id')->searchable()->sortable(),
            TextColumn::make('publishingJob.id')->searchable()->sortable(),
            TextColumn::make('provider')->badge()->searchable()->sortable(),
            TextColumn::make('provider_status')->badge()->searchable()->sortable(),
            TextColumn::make('external_id')->searchable()->sortable(),
            TextColumn::make('external_url')->searchable()->sortable(),
            TextColumn::make('correlation_id')->searchable()->sortable(),
        ])->recordActions([

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
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListPublicationResults::route('/')];
    }
}