<?php

namespace App\Filament\Resources\BusinessHealthResults;

use App\Filament\Resources\BusinessHealthResults\Pages\ListBusinessHealthResults;
use App\Filament\Resources\Concerns\ScopesPhaseSixRecords;
use App\Models\BusinessHealthResult;
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

class BusinessHealthResultResource extends Resource
{
    use ScopesPhaseSixRecords;

    protected static ?string $model = BusinessHealthResult::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Business Health';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::authorizedEnterpriseIds()))->searchable()->preload(),
            Select::make('financial_report_id')->relationship('financialReport', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload(),
            TextInput::make('health_status'),
            Textarea::make('metrics')->rows(4),
            Textarea::make('source_snapshot')->rows(4),
            DateTimePicker::make('evaluated_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise_id')->searchable()->sortable(),
            TextColumn::make('financial_report_id')->searchable()->sortable(),
            TextColumn::make('health_status')->searchable()->sortable(),
            TextColumn::make('evaluated_at')->searchable()->sortable(),
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
            'index' => ListBusinessHealthResults::route('/'),
        ];
    }
}
