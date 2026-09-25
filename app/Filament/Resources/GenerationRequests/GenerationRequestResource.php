<?php

namespace App\Filament\Resources\GenerationRequests;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\GenerationRequests\Pages\ListGenerationRequests;
use App\Models\GenerationRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GenerationRequestResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = GenerationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Media';

    protected static ?string $navigationLabel = 'Generation Requests';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('contentItem.title')->searchable()->sortable(),
            TextColumn::make('asset.name')->searchable()->sortable(),
            TextColumn::make('type')->badge()->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('correlation_id')->searchable()->sortable(),
            TextColumn::make('external_request_id')->searchable()->sortable(),
            TextColumn::make('failure_code')->searchable()->sortable(),
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
        return ['index' => ListGenerationRequests::route('/')];
    }
}