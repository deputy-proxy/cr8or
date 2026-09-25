<?php

namespace App\Filament\Resources\FinancialPeriods;

use App\Filament\Resources\Concerns\ScopesPhaseSixRecords;
use App\Filament\Resources\FinancialPeriods\Pages\CreateFinancialPeriods;
use App\Filament\Resources\FinancialPeriods\Pages\EditFinancialPeriods;
use App\Filament\Resources\FinancialPeriods\Pages\ListFinancialPeriods;
use App\Models\FinancialPeriod;
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

class FinancialPeriodResource extends Resource
{
    use ScopesPhaseSixRecords;

    protected static ?string $model = FinancialPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Financial Periods';

    protected static ?int $navigationSort = 110;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            TextInput::make('name')->disabledOn('edit'),
            DatePicker::make('period_start')->disabledOn('edit'),
            DatePicker::make('period_end')->disabledOn('edit'),
            Select::make('status')->options(['active' => 'Active', 'closed' => 'Closed'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise_id')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('period_start')->searchable()->sortable(),
            TextColumn::make('period_end')->searchable()->sortable(),
            TextColumn::make('status')->searchable()->sortable(),
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
            'index' => ListFinancialPeriods::route('/'),
            'create' => CreateFinancialPeriods::route('/create'),
            'edit' => EditFinancialPeriods::route('/{record}/edit'),
        ];
    }
}
