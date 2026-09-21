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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(), Textarea::make('description')->rows(4), TextInput::make('industry')->maxLength(255), TextInput::make('business_model')->maxLength(255), TextInput::make('target_market')->maxLength(255), TextInput::make('geography')->maxLength(255)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('enterprise.name')->searchable()->sortable(), TextColumn::make('status')->badge()])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
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
        return ['index' => ListEnterpriseContexts::route('/'), 'create' => CreateEnterpriseContext::route('/create'), 'edit' => EditEnterpriseContext::route('/{record}/edit')];
    }
}
