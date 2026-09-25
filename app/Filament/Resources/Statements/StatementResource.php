<?php

namespace App\Filament\Resources\Statements;

use App\Filament\Resources\Concerns\ScopesPhaseSixRecords;
use App\Filament\Resources\Statements\Pages\CreateStatements;
use App\Filament\Resources\Statements\Pages\EditStatements;
use App\Filament\Resources\Statements\Pages\ListStatements;
use App\Models\Statement;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StatementResource extends Resource
{
    use ScopesPhaseSixRecords;

    protected static ?string $model = Statement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Statements';

    protected static ?int $navigationSort = 150;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')->relationship('organization', 'name', fn (Builder $q) => $q->whereIn('id', static::authorizedOrganizationIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('financial_account_id')->relationship('financialAccount', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            TextInput::make('source')->disabledOn('edit'),
            TextInput::make('source_reference')->disabledOn('edit'),
            DatePicker::make('statement_date'),
            DatePicker::make('period_start'),
            DatePicker::make('period_end'),
            Textarea::make('metadata')->rows(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('organization_id')->searchable()->sortable(),
            TextColumn::make('enterprise_id')->searchable()->sortable(),
            TextColumn::make('financial_account_id')->searchable()->sortable(),
            TextColumn::make('source')->searchable()->sortable(),
            TextColumn::make('source_reference')->searchable()->sortable(),
            TextColumn::make('statement_date')->searchable()->sortable(),
            TextColumn::make('period_start')->searchable()->sortable(),
            TextColumn::make('period_end')->searchable()->sortable(),
            TextColumn::make('metadata')->searchable()->sortable(),
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
            'index' => ListStatements::route('/'),
            'create' => CreateStatements::route('/create'),
            'edit' => EditStatements::route('/{record}/edit'),
        ];
    }
}
