<?php

namespace App\Filament\Resources\TransactionCategories;

use App\Filament\Resources\Concerns\ScopesPhaseSixRecords;
use App\Filament\Resources\TransactionCategories\Pages\CreateTransactionCategories;
use App\Filament\Resources\TransactionCategories\Pages\EditTransactionCategories;
use App\Filament\Resources\TransactionCategories\Pages\ListTransactionCategories;
use App\Models\TransactionCategory;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionCategoryResource extends Resource
{
    use ScopesPhaseSixRecords;

    protected static ?string $model = TransactionCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Transaction Categories';

    protected static ?int $navigationSort = 140;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::authorizedEnterpriseIds()))->searchable()->preload(),
            TextInput::make('name'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise_id')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('enterprise_id', static::authorizedEnterpriseIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactionCategories::route('/'),
            'create' => CreateTransactionCategories::route('/create'),
            'edit' => EditTransactionCategories::route('/{record}/edit'),
        ];
    }
}
