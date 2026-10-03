<?php

namespace App\Filament\Resources\Visions;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Visions\Pages\CreateVision;
use App\Filament\Resources\Visions\Pages\ListVisions;
use App\Models\Vision;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisionResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Vision::class;

    protected static ?string $modelLabel = 'Vision';

    protected static ?string $pluralModelLabel = 'Visions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|\UnitEnum|null $navigationGroup = 'Enterprise Context';

    protected static ?string $navigationLabel = 'Visions';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload()->required(),
            Textarea::make('statement')->required()->rows(6),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('version')->sortable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('effective_from')->dateTime()->sortable(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisions::route('/'),
            'create' => CreateVision::route('/create'),
        ];
    }
}
