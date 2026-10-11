<?php

namespace App\Filament\Resources\EnterpriseContexts;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\EnterpriseContexts\Pages\CreateEnterpriseContext;
use App\Filament\Resources\EnterpriseContexts\Pages\EditEnterpriseContext;
use App\Filament\Resources\EnterpriseContexts\Pages\ListEnterpriseContexts;
use App\Models\EnterpriseContext;
use BackedEnum;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class EnterpriseContextResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = EnterpriseContext::class;

    protected static ?string $modelLabel = 'Enterprise Context';

    protected static ?string $pluralModelLabel = 'Enterprise Contexts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Strategy & Planning';

    protected static ?string $navigationLabel = 'Enterprise Contexts';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload()->required(),
            Textarea::make('mission')->label('Mission')->rows(3)->maxLength(10000),
            Textarea::make('vision')->label('Vision')->rows(3)->maxLength(10000),
            Builder::make('values')
                ->label('Values')
                ->blocks([
                    Block::make('value')->label('Value')->schema([
                        TextInput::make('name')->label('Value')->required()->maxLength(120),
                        Textarea::make('description')->rows(2)->maxLength(1000),
                    ]),
                ])
                ->default([])
                ->afterStateHydrated(function (Builder $component): void {
                    $state = $component->getRawState();
                    if (is_array($state) && array_is_list($state) && isset($state[0]['name'])) {
                        $component->state(array_map(static fn (array $value): array => ['type' => 'value', 'data' => $value], $state));
                        $component->hydrateItems();
                    }
                })
                ->dehydrateStateUsing(fn (?array $state): array => collect($state ?? [])->map(fn (array $item): array => $item['data'] ?? [])->values()->all()),
            Textarea::make('description')->rows(4),
            TextInput::make('industry')->maxLength(255),
            TextInput::make('business_model')->maxLength(255),
            TextInput::make('target_market')->maxLength(255),
            TextInput::make('geography')->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(), TextColumn::make('industry')->searchable()->sortable(), TextColumn::make('business_model')->label('Business Model')->searchable()->sortable(), TextColumn::make('target_market')->label('Target Market')->searchable(), TextColumn::make('geography')->searchable(), TextColumn::make('updated_at')->dateTime()->sortable()])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
    }

    public static function getEloquentQuery(): EloquentBuilder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (EloquentBuilder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListEnterpriseContexts::route('/'), 'create' => CreateEnterpriseContext::route('/create'), 'edit' => EditEnterpriseContext::route('/{record}/edit')];
    }
}
