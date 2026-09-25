<?php

namespace App\Filament\Resources\Kpis;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Kpis\Pages\CreateKpi;
use App\Filament\Resources\Kpis\Pages\EditKpi;
use App\Filament\Resources\Kpis\Pages\ListKpis;
use App\Models\Kpi;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KpiResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Kpi::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Strategy';

    protected static ?string $navigationLabel = 'KPIs';

    protected static ?int $navigationSort = 16;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(), TextInput::make('name')->required()->maxLength(255), TextInput::make('definition')->required()->maxLength(255), TextInput::make('unit')->maxLength(255), TextInput::make('target_value')->numeric(), TextInput::make('current_value')->numeric(), Select::make('status')->options(['active' => 'Active', 'archived' => 'Archived'])->default('active')->required()]);
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
        return ['index' => ListKpis::route('/'), 'create' => CreateKpi::route('/create'), 'edit' => EditKpi::route('/{record}/edit')];
    }
}