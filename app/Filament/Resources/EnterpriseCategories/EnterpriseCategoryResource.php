<?php

namespace App\Filament\Resources\EnterpriseCategories;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\EnterpriseCategories\Pages\CreateEnterpriseCategory;
use App\Filament\Resources\EnterpriseCategories\Pages\EditEnterpriseCategory;
use App\Filament\Resources\EnterpriseCategories\Pages\ListEnterpriseCategories;
use App\Models\EnterpriseCategory;
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
use Illuminate\Support\Facades\Gate;

class EnterpriseCategoryResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = EnterpriseCategory::class;

    protected static ?string $modelLabel = 'Enterprise Category';

    protected static ?string $pluralModelLabel = 'Enterprise Categories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|\UnitEnum|null $navigationGroup = 'Organization & Access';

    protected static ?string $navigationLabel = 'Enterprise Categories';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')
                ->relationship('organization', 'name', modifyQueryUsing: fn (Builder $query) => $query->whereIn('id', static::manageableOrganizationIds()))
                ->searchable()->preload()->required()->live(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->required()->alphaDash()->maxLength(255),
            Textarea::make('description')->rows(3)->maxLength(2000),
            TextInput::make('sort_order')->numeric()->integer()->minValue(0)->default(0)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('slug')->searchable(),
            TextColumn::make('organization.name')->searchable()->sortable(),
            TextColumn::make('sort_order')->sortable(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return auth()->check() && Gate::allows('create', static::getModel());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnterpriseCategories::route('/'),
            'create' => CreateEnterpriseCategory::route('/create'),
            'edit' => EditEnterpriseCategory::route('/{record}/edit'),
        ];
    }
}
