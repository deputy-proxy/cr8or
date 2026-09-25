<?php

namespace App\Filament\Resources\FinancialAccounts;

use App\Filament\Resources\Concerns\ScopesPhaseSixRecords;
use App\Filament\Resources\FinancialAccounts\Pages\CreateFinancialAccounts;
use App\Filament\Resources\FinancialAccounts\Pages\EditFinancialAccounts;
use App\Filament\Resources\FinancialAccounts\Pages\ListFinancialAccounts;
use App\Models\FinancialAccount;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FinancialAccountResource extends Resource
{
    use ScopesPhaseSixRecords;

    protected static ?string $model = FinancialAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Financial Accounts';

    protected static ?int $navigationSort = 100;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::authorizedEnterpriseIds()))->searchable()->preload()->required()->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('type')->options(['bank' => 'Bank', 'cash' => 'Cash', 'credit_card' => 'Credit Card', 'other' => 'Other'])->required(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->required(),
            TextInput::make('currency')->required()->maxLength(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('type')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('currency')->sortable(),
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
            'index' => ListFinancialAccounts::route('/'),
            'create' => CreateFinancialAccounts::route('/create'),
            'edit' => EditFinancialAccounts::route('/{record}/edit'),
        ];
    }
}
