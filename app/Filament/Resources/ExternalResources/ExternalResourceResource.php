<?php

namespace App\Filament\Resources\ExternalResources;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\ExternalResources\Pages\ListExternalResources;
use App\Models\ExternalResource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExternalResourceResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = ExternalResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('organization.name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('contentItem.title')->searchable()->sortable(),
            TextColumn::make('asset.name')->searchable()->sortable(),
            TextColumn::make('agentExecution.id')->searchable()->sortable(),
            TextColumn::make('provider')->badge()->searchable()->sortable(),
            TextColumn::make('resource_type')->searchable()->sortable(),
            TextColumn::make('external_id')->searchable()->sortable(),
        ])->recordActions([

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

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListExternalResources::route('/')];
    }
}
