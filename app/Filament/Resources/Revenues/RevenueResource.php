<?php

namespace App\Filament\Resources\Revenues;

use App\Filament\Resources\Concerns\ScopesPhaseSixRecords;
use App\Filament\Resources\Revenues\Pages\CreateRevenues;
use App\Filament\Resources\Revenues\Pages\EditRevenues;
use App\Filament\Resources\Revenues\Pages\ListRevenues;
use App\Models\Revenue;
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

class RevenueResource extends Resource
{
    use ScopesPhaseSixRecords;

    protected static ?string $model = Revenue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('financial_account_id')->relationship('financialAccount', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('transaction_id')->relationship('transaction', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('financial_period_id')->relationship('financialPeriod', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            TextInput::make('amount'),
            TextInput::make('currency'),
            DatePicker::make('revenue_date'),
            TextInput::make('source'),
            TextInput::make('reference'),
            TextInput::make('description'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise_id')->searchable()->sortable(),
            TextColumn::make('financial_account_id')->searchable()->sortable(),
            TextColumn::make('transaction_id')->searchable()->sortable(),
            TextColumn::make('financial_period_id')->searchable()->sortable(),
            TextColumn::make('amount')->searchable()->sortable(),
            TextColumn::make('currency')->searchable()->sortable(),
            TextColumn::make('revenue_date')->searchable()->sortable(),
            TextColumn::make('source')->searchable()->sortable(),
            TextColumn::make('reference')->searchable()->sortable(),
            TextColumn::make('description')->searchable()->sortable(),
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

    public static function canCreate(): bool
    {
        return auth()->check() && static::canManageAnyEnterprise();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRevenues::route('/'),
            'create' => CreateRevenues::route('/create'),
            'edit' => EditRevenues::route('/{record}/edit'),
        ];
    }
}