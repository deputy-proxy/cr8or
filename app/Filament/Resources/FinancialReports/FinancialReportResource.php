<?php

namespace App\Filament\Resources\FinancialReports;

use App\Filament\Resources\Concerns\ScopesPhaseSixRecords;
use App\Filament\Resources\FinancialReports\Pages\ListFinancialReports;
use App\Models\FinancialReport;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FinancialReportResource extends Resource
{
    use ScopesPhaseSixRecords;

    protected static ?string $model = FinancialReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload(),
            Select::make('financial_period_id')->relationship('financialPeriod', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload(),
            Select::make('financial_account_id')->relationship('financialAccount', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload(),
            Select::make('transaction_category_id')->relationship('category', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload(),
            TextInput::make('currency'),
            Textarea::make('metrics')->rows(4),
            Textarea::make('source_snapshot')->rows(4),
            DateTimePicker::make('generated_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise_id')->searchable()->sortable(),
            TextColumn::make('financial_period_id')->searchable()->sortable(),
            TextColumn::make('financial_account_id')->searchable()->sortable(),
            TextColumn::make('transaction_category_id')->searchable()->sortable(),
            TextColumn::make('currency')->searchable()->sortable(),
            TextColumn::make('generated_at')->searchable()->sortable(),
        ])->recordActions([

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
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialReports::route('/'),
        ];
    }
}