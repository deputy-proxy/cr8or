<?php

namespace App\Filament\Resources\IntegrationConnections;

use App\Data\Integrations\ConfigurationFieldDefinition;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\IntegrationConnections\Pages\CreateIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\Pages\EditIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\Pages\ListIntegrationConnections;
use App\Models\IntegrationConnection;
use App\Services\IntegrationRegistry;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class IntegrationConnectionResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = IntegrationConnection::class;

    protected static ?string $modelLabel = 'Integration Connection';

    protected static ?string $pluralModelLabel = 'Integration Connections';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations & External Systems';

    protected static ?string $navigationLabel = 'Integration Connections';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')->relationship('organization', 'name')->searchable()->preload()->required(),
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload()->required(),
            Select::make('provider')
                ->options(fn (): array => collect(app(IntegrationRegistry::class)->providers())
                    ->mapWithKeys(fn ($provider): array => [$provider->key => $provider->name])
                    ->all())
                ->searchable()
                ->live()
                ->required(),
            TextInput::make('external_account_id')->maxLength(255),
            TextInput::make('credential_reference')->required()->maxLength(255),
            Builder::make('configuration')
                ->label('Configuration')
                ->blocks(fn (Get $get): array => static::configurationBlocks((string) $get('provider')))
                ->afterStateHydrated(function (Builder $component): void {
                    $configuration = $component->getRawState();
                    $component->state(static::configurationToBuilderState(is_array($configuration) ? $configuration : null));
                    $component->hydrateItems();
                })
                ->dehydrateStateUsing(fn (?array $state): array => static::builderStateToConfiguration($state))
                ->mutateDehydratedStateUsing(fn (mixed $state): mixed => $state)
                ->reorderable(false),
            Select::make('status')->options([
                IntegrationConnection::STATUS_ACTIVE => 'Active',
                IntegrationConnection::STATUS_DISABLED => 'Disabled',
                IntegrationConnection::STATUS_DEGRADED => 'Degraded',
                IntegrationConnection::STATUS_REVOKED => 'Revoked',
            ])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('organization.name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('provider')->badge()->searchable()->sortable(),
            TextColumn::make('external_account_id')->searchable()->sortable(),
            TextColumn::make('credential_reference')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
        ])->recordActions([
            EditAction::make(), DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): EloquentBuilder
    {
        return parent::getEloquentQuery()->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    /** @return EloquentBuilder<\App\Models\Enterprise> */
    protected static function authorizedEnterpriseIds(): EloquentBuilder
    {
        return \App\Models\Enterprise::query()->select('enterprises.id')->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('create', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListIntegrationConnections::route('/'), 'create' => CreateIntegrationConnection::route('/create'), 'edit' => EditIntegrationConnection::route('/{record}/edit')];
    }

    /** @return list<Block> */
    protected static function configurationBlocks(?string $provider): array
    {
        if ($provider === null || $provider === '') {
            return [];
        }

        return array_map(
            static fn (ConfigurationFieldDefinition $field): Block => static::configurationBlock($field),
            app(IntegrationRegistry::class)->provider($provider)->configurationFields,
        );
    }

    protected static function configurationBlock(ConfigurationFieldDefinition $field): Block
    {
        $input = match ($field->type) {
            'url' => TextInput::make('value')->url(),
            'select' => Select::make('value')->options($field->options)->searchable(),
            'boolean' => Select::make('value')->options([true => 'Yes', false => 'No']),
            'integer' => TextInput::make('value')->numeric(),
            default => TextInput::make('value'),
        };

        return Block::make($field->key)
            ->label($field->label)
            ->maxItems(1)
            ->schema([
                $input
                    ->label($field->label)
                    ->required($field->required),
            ]);
    }

    /**
     * Convert the persisted provider configuration into the native Filament Builder state.
     *
     * @param  array<string, mixed>|null  $configuration
     * @return array<int, array{type: string, data: array{value: mixed}}>
     */
    public static function configurationToBuilderState(?array $configuration): array
    {
        return collect($configuration ?? [])
            ->map(fn (mixed $value, string|int $key): array => [
                'type' => (string) $key,
                'data' => ['value' => $value],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string, array{type: string, data: array{value: mixed}}>|null  $state
     * @return array<string, mixed>
     */
    public static function builderStateToConfiguration(?array $state): array
    {
        $configuration = [];

        foreach ($state ?? [] as $item) {
            $configuration[$item['type']] = $item['data']['value'] ?? null;
        }

        return $configuration;
    }
}
