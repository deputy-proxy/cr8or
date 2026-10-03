<?php

namespace App\Filament\Resources\Transactions;

use App\Filament\Resources\Concerns\ScopesEnterpriseRecords;
use App\Filament\Resources\Transactions\Pages\CreateTransactions;
use App\Filament\Resources\Transactions\Pages\EditTransactions;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Models\Transaction;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionResource extends Resource
{
    use ScopesEnterpriseRecords;

    protected static ?string $model = Transaction::class;

    protected static ?string $modelLabel = 'Transaction';

    protected static ?string $pluralModelLabel = 'Transactions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload(),
            Select::make('financial_account_id')->relationship('financialAccount', 'name')->searchable()->preload(),
            Select::make('transaction_category_id')->relationship('category', 'name')->searchable()->preload(),
            Select::make('financial_period_id')->relationship('financialPeriod', 'name')->searchable()->preload(),
            TextInput::make('amount'),
            DatePicker::make('transaction_date'),
            TextInput::make('description'),
            TextInput::make('reference'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise_id')->searchable()->sortable(),
            TextColumn::make('financial_account_id')->searchable()->sortable(),
            TextColumn::make('transaction_category_id')->searchable()->sortable(),
            TextColumn::make('financial_period_id')->searchable()->sortable(),
            TextColumn::make('amount')->searchable()->sortable(),
            TextColumn::make('transaction_date')->searchable()->sortable(),
            TextColumn::make('description')->searchable()->sortable(),
            TextColumn::make('reference')->searchable()->sortable(),
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
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
            'create' => CreateTransactions::route('/create'),
            'edit' => EditTransactions::route('/{record}/edit'),
        ];
    }
}
