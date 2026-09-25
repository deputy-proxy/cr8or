<?php

namespace App\Filament\Resources\StatementEntries;

use App\Filament\Resources\Concerns\ScopesPhaseSixRecords;
use App\Filament\Resources\StatementEntries\Pages\CreateStatementEntries;
use App\Filament\Resources\StatementEntries\Pages\EditStatementEntries;
use App\Filament\Resources\StatementEntries\Pages\ListStatementEntries;
use App\Models\StatementEntry;
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

class StatementEntryResource extends Resource
{
    use ScopesPhaseSixRecords;

    protected static ?string $model = StatementEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Statement Entries';

    protected static ?int $navigationSort = 160;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')->relationship('organization', 'name', fn (Builder $q) => $q->whereIn('id', static::authorizedOrganizationIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('statement_id')->relationship('statement', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('financial_account_id')->relationship('financialAccount', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('transaction_id')->relationship('transaction', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload(),
            TextInput::make('source')->disabledOn('edit'),
            TextInput::make('source_reference')->disabledOn('edit'),
            TextInput::make('amount'),
            DatePicker::make('entry_date'),
            TextInput::make('description'),
            TextInput::make('reference'),
            Textarea::make('metadata')->rows(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('organization_id')->searchable()->sortable(),
            TextColumn::make('enterprise_id')->searchable()->sortable(),
            TextColumn::make('statement_id')->searchable()->sortable(),
            TextColumn::make('financial_account_id')->searchable()->sortable(),
            TextColumn::make('transaction_id')->searchable()->sortable(),
            TextColumn::make('source')->searchable()->sortable(),
            TextColumn::make('source_reference')->searchable()->sortable(),
            TextColumn::make('amount')->searchable()->sortable(),
            TextColumn::make('entry_date')->searchable()->sortable(),
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
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function canCreate(): bool
    {
        return auth()->check() && static::canManageAnyEnterprise();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStatementEntries::route('/'),
            'create' => CreateStatementEntries::route('/create'),
            'edit' => EditStatementEntries::route('/{record}/edit'),
        ];
    }
}
