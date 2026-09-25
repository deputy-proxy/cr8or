<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Resources\Concerns\ScopesPhaseSixRecords;
use App\Filament\Resources\Invoices\Pages\CreateInvoices;
use App\Filament\Resources\Invoices\Pages\EditInvoices;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
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

class InvoiceResource extends Resource
{
    use ScopesPhaseSixRecords;

    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?int $navigationSort = 170;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('customer_id')->relationship('customer', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            Select::make('partner_id')->relationship('partner', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::authorizedEnterpriseIds()))->searchable()->preload()->disabledOn('edit'),
            TextInput::make('invoice_number')->disabledOn('edit'),
            DatePicker::make('issue_date')->disabledOn('edit'),
            DatePicker::make('due_date')->disabledOn('edit'),
            TextInput::make('total')->disabledOn('edit'),
            TextInput::make('currency')->disabledOn('edit'),
            Select::make('status')->options(['draft' => 'Draft', 'issued' => 'Issued', 'overdue' => 'Overdue', 'cancelled' => 'Cancelled'])->required(),
            TextInput::make('counterparty_name_snapshot')->disabledOn('edit'),
            TextInput::make('counterparty_email_snapshot')->disabledOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise_id')->searchable()->sortable(),
            TextColumn::make('customer_id')->searchable()->sortable(),
            TextColumn::make('partner_id')->searchable()->sortable(),
            TextColumn::make('invoice_number')->searchable()->sortable(),
            TextColumn::make('issue_date')->searchable()->sortable(),
            TextColumn::make('due_date')->searchable()->sortable(),
            TextColumn::make('total')->searchable()->sortable(),
            TextColumn::make('currency')->searchable()->sortable(),
            TextColumn::make('status')->searchable()->sortable(),
            TextColumn::make('counterparty_name_snapshot')->searchable()->sortable(),
            TextColumn::make('counterparty_email_snapshot')->searchable()->sortable(),
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
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoices::route('/create'),
            'edit' => EditInvoices::route('/{record}/edit'),
        ];
    }
}
