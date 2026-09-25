<?php

namespace App\Filament\Resources\EnterpriseContexts;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\EnterpriseContexts\Pages\CreateEnterpriseContext;
use App\Filament\Resources\EnterpriseContexts\Pages\EditEnterpriseContext;
use App\Filament\Resources\EnterpriseContexts\Pages\ListEnterpriseContexts;
use App\Models\EnterpriseContext;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnterpriseContextResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = EnterpriseContext::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Organization';

    protected static ?string $navigationLabel = 'Enterprise Contexts';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload()->required(), Textarea::make('description')->rows(4), TextInput::make('industry')->maxLength(255), TextInput::make('business_model')->maxLength(255), TextInput::make('target_market')->maxLength(255), TextInput::make('geography')->maxLength(255)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(), TextColumn::make('industry')->searchable()->sortable(), TextColumn::make('business_model')->label('Business Model')->searchable()->sortable(), TextColumn::make('target_market')->label('Target Market')->searchable(), TextColumn::make('geography')->searchable(), TextColumn::make('updated_at')->dateTime()->sortable()])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function getPages(): array
    {
        return ['index' => ListEnterpriseContexts::route('/'), 'create' => CreateEnterpriseContext::route('/create'), 'edit' => EditEnterpriseContext::route('/{record}/edit')];
    }
}
