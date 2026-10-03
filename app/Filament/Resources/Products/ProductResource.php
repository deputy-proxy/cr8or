<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Product::class;

    protected static ?string $modelLabel = 'Product';

    protected static ?string $pluralModelLabel = 'Products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|\UnitEnum|null $navigationGroup = 'Enterprise Context';

    protected static ?string $navigationLabel = 'Products';

    protected static ?int $navigationSort = 180;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload()->required(), TextInput::make('name')->required()->maxLength(255), TextInput::make('slug')->required()->maxLength(255), Select::make('status')->options(['active' => 'Active', 'archived' => 'Archived'])->default('active')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('enterprise.name')->searchable()->sortable(), TextColumn::make('status')->badge()])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
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
        return ['index' => ListProducts::route('/'), 'create' => CreateProduct::route('/create'), 'edit' => EditProduct::route('/{record}/edit')];
    }
}
