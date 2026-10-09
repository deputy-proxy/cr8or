<?php

namespace App\Filament\Resources\RenderRequests;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\RenderRequests\Pages\ListRenderRequests;
use App\Models\RenderRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RenderRequestResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = RenderRequest::class;

    protected static ?string $modelLabel = 'Render Request';

    protected static ?string $pluralModelLabel = 'Render Requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Media Production';

    protected static ?string $navigationLabel = 'Render Requests';

    protected static ?int $navigationSort = 40;

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
            TextColumn::make('sourceVersion.version')->searchable()->sortable(),
            TextColumn::make('type')->badge()->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('correlation_id')->searchable()->sortable(),
            TextColumn::make('external_request_id')->searchable()->sortable(),
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
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('create', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListRenderRequests::route('/')];
    }
}
