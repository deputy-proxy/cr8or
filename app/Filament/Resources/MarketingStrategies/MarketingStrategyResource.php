<?php

namespace App\Filament\Resources\MarketingStrategies;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\MarketingStrategies\Pages\CreateMarketingStrategy;
use App\Filament\Resources\MarketingStrategies\Pages\EditMarketingStrategy;
use App\Filament\Resources\MarketingStrategies\Pages\ListMarketingStrategies;
use App\Models\MarketingStrategy;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketingStrategyResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = MarketingStrategy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Strategy';

    protected static ?string $navigationLabel = 'Marketing Strategies';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            TextInput::make('name')->required()->maxLength(255), Textarea::make('description')->rows(4),
            Select::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'])->default('draft')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(), TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(), TextColumn::make('status')->badge()->sortable(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
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
        return ['index' => ListMarketingStrategies::route('/'), 'create' => CreateMarketingStrategy::route('/create'), 'edit' => EditMarketingStrategy::route('/{record}/edit')];
    }
}