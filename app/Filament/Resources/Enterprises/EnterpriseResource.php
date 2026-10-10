<?php

namespace App\Filament\Resources\Enterprises;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Enterprises\Pages\CreateEnterprise;
use App\Filament\Resources\Enterprises\Pages\EditEnterprise;
use App\Filament\Resources\Enterprises\Pages\ListEnterprises;
use App\Models\Enterprise;
use App\Models\EnterpriseCategory;
use App\Models\EnterpriseGroup;
use BackedEnum;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class EnterpriseResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Enterprise::class;

    protected static ?string $modelLabel = 'Enterprise';

    protected static ?string $pluralModelLabel = 'Enterprises';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|\UnitEnum|null $navigationGroup = 'Organization & Access';

    protected static ?string $navigationLabel = 'Enterprises';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')->relationship('organization', 'name', modifyQueryUsing: fn (EloquentBuilder $query) => $query->whereIn('id', static::authorizedOrganizationIds()))->searchable()->preload()->required()->live(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->required()->alphaDash()->maxLength(255),
            Select::make('status')->options(['active' => 'Active', 'archived' => 'Archived'])->default('active')->required(),
            Select::make('enterprise_group_id')->label('Portfolio Group')->options(fn (Get $get): array => EnterpriseGroup::query()->where('organization_id', (int) $get('organization_id'))->whereIn('organization_id', static::authorizedOrganizationIds())->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())->searchable()->preload()->nullable(),
            Select::make('enterprise_category_id')->label('Portfolio Category')->options(fn (Get $get): array => EnterpriseCategory::query()->where('organization_id', (int) $get('organization_id'))->whereIn('organization_id', static::authorizedOrganizationIds())->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())->searchable()->preload()->nullable(),
            TextInput::make('github_repository')->label('GitHub Repository (owner/repo)')->maxLength(255)->nullable()->placeholder('owner/repository'),
            TextInput::make('github_repository_url')->label('GitHub Repository URL')->url()->maxLength(2048)->nullable()->placeholder('https://github.com/owner/repository'),
            TextInput::make('website_domain')->label('Website Hostname')->maxLength(253)->nullable()->placeholder('example.com')->helperText('Lowercase hostname only. No scheme or path.'),
            Builder::make('connections')
                ->label('Enterprise Connections')
                ->blocks([
                    Block::make('connection')->schema([
                        Select::make('target_enterprise_id')->label('Connected Enterprise')->options(fn (Get $get, ?Enterprise $record): array => Enterprise::query()->where('organization_id', (int) $get('../../../organization_id'))->whereIn('organization_id', static::authorizedOrganizationIds())->when($record !== null, fn (EloquentBuilder $query) => $query->whereKeyNot($record->id))->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                        Select::make('type')->options(array_combine(Enterprise::CONNECTION_TYPES, array_map(static fn (string $type): string => str($type)->replace('_', ' ')->title()->toString(), Enterprise::CONNECTION_TYPES)))->required(),
                        Select::make('direction')->options(['incoming' => 'Incoming', 'outgoing' => 'Outgoing', 'bidirectional' => 'Bidirectional'])->required(),
                        Textarea::make('description')->rows(2)->maxLength(500),
                    ]),
                ])
                ->afterStateHydrated(function (Builder $component): void {
                    $state = $component->getRawState();
                    if (is_array($state) && array_is_list($state) && isset($state[0]['target_enterprise_id'])) {
                        $component->state(array_map(static fn (array $connection): array => ['type' => 'connection', 'data' => $connection], $state));
                        $component->hydrateItems();
                    }
                })
                ->dehydrateStateUsing(function (?array $state): array {
                    return collect($state ?? [])->map(fn (array $item): array => $item['data'] ?? [])->values()->all();
                })
                ->reorderable(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('organization.name')->searchable()->sortable(), TextColumn::make('group.name')->label('Group')->sortable(), TextColumn::make('category.name')->label('Category')->sortable(), TextColumn::make('status')->badge()])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
    }

    public static function getEloquentQuery(): EloquentBuilder
    {
        return parent::getEloquentQuery()->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListEnterprises::route('/'), 'create' => CreateEnterprise::route('/create'), 'edit' => EditEnterprise::route('/{record}/edit')];
    }
}
