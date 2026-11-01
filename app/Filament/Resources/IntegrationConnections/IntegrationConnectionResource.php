<?php

namespace App\Filament\Resources\IntegrationConnections;

use App\Data\Integrations\ConfigurationFieldDefinition;
use App\Enums\SocialRevocationResult;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\IntegrationConnections\Pages\CreateIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\Pages\EditIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\Pages\ListIntegrationConnections;
use App\Jobs\DiscoverGitHubRepositories;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\IntegrationRegistry;
use App\Services\Integrations\IntegrationEnterpriseAccessService;
use App\Services\Social\SocialAuthorizationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class IntegrationConnectionResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = IntegrationConnection::class;

    protected static ?string $modelLabel = 'Integration Connection';

    protected static ?string $pluralModelLabel = 'Integration Connections';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflows & Integrations';

    protected static ?string $navigationLabel = 'Integration Connections';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')->relationship('organization', 'name', modifyQueryUsing: fn (EloquentBuilder $query) => $query->whereIn('id', static::authorizedOrganizationIds()))->searchable()->preload()->required()->live(),
            Select::make('enterprise_id')->options(fn (Get $get): array => Enterprise::query()
                ->whereIn('organization_id', static::authorizedOrganizationIds())
                ->when($get('organization_id') !== null, fn (EloquentBuilder $query) => $query->where('organization_id', (int) $get('organization_id')), fn (EloquentBuilder $query) => $query->whereRaw('1 = 0'))
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
                ->searchable()->preload()->nullable()->live(),
            Select::make('provider')
                ->options(fn (): array => collect(app(IntegrationRegistry::class)->providers())
                    ->mapWithKeys(fn ($provider): array => [$provider->key => $provider->name])
                    ->all())
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                    if ($state === 'github') {
                        $set('credential_reference', 'github-app-installation');
                    } elseif ($get('credential_reference') === 'github-app-installation') {
                        $set('credential_reference', null);
                    }
                })
                ->required(),
            Select::make('oauth_client_id')
                ->label('Media OAuth client')
                ->options(fn (): array => DB::table('oauth_clients')
                    ->where('revoked', false)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->mapWithKeys(fn (object $client): array => [(string) $client->id => $client->name.' ('.$client->id.')'])
                    ->all())
                ->searchable()
                ->preload()
                ->nullable()
                ->visible(fn (Get $get): bool => app(IntegrationRegistry::class)->isMediaProvider($get('provider')))
                ->helperText('Associating a client does not grant Enterprise access. Manage grants separately.')
                ->dehydrated(fn (Get $get): bool => app(IntegrationRegistry::class)->isMediaProvider($get('provider'))),
            TextInput::make('external_account_id')->maxLength(255),
            TextInput::make('github_installation_id')->label('GitHub App Installation ID')->numeric()->rule('integer')->minValue(1)->visible(fn (Get $get): bool => $get('provider') === 'github')->dehydrated(fn (Get $get): bool => $get('provider') === 'github')->disabledOn('edit')->helperText('Install the CR8OR GitHub App on the target GitHub account, then enter its installation ID. The App ID and private key are configured server-side.'),
            TextInput::make('credential_reference')->required(fn (Get $get): bool => ! ($get('provider') === 'github' && filled($get('github_installation_id'))))->maxLength(255)->helperText(fn (Get $get): string => $get('provider') === 'github'
                ? 'For GitHub App installations, keep github-app-installation. The installation ID is stored separately; tokens are generated and cached server-side.'
                : 'Opaque identifier only, never a token, password, API key, or other secret. The provider-specific credential resolver uses this reference.'),
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
                IntegrationConnection::STATUS_EXPIRED => 'Expired',
                IntegrationConnection::STATUS_RECONNECT_REQUIRED => 'Reconnect required',
            ])->default(IntegrationConnection::STATUS_ACTIVE)->required()->disabled()->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('organization.name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('provider')->badge()->searchable()->sortable(),
            TextColumn::make('oauthClient.name')->label('OAuth client')->placeholder('Not linked')->toggleable(),
            TextColumn::make('active_enterprise_access_count')
                ->label('Authorized Enterprises')
                ->state(fn (IntegrationConnection $record): int => $record->oauth_client_id === null ? 0 : DB::table('integration_enterprise_access')
                    ->where('oauth_client_id', $record->oauth_client_id)
                    ->where('active', true)
                    ->count()),
            TextColumn::make('external_account_id')->searchable()->sortable(),
            TextColumn::make('github_installation_id')->label('GitHub Installation')->toggleable(),
            TextColumn::make('github_account_login')->label('GitHub Account')->state(fn (IntegrationConnection $record): ?string => data_get($record->metadata, 'github_account_login'))->toggleable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
        ])->recordActions([
            EditAction::make()->visible(fn (IntegrationConnection $record): bool => $record->social_identity_id === null),
            Action::make('discoverGitHubRepositories')->label('Discover GitHub repositories')->icon(Heroicon::OutlinedMagnifyingGlass)
                ->visible(fn (IntegrationConnection $record): bool => $record->provider === 'github' && $record->github_installation_id !== null && $record->isOperational() && Gate::allows('update', $record))
                ->requiresConfirmation()
                ->action(function (IntegrationConnection $record): void {
                    DiscoverGitHubRepositories::dispatch((int) $record->id);
                    Notification::make()->success()->title('Repository discovery queued')->send();
                }),
            Action::make('manageEnterpriseAccess')
                ->label('Manage Enterprise access')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->visible(fn (IntegrationConnection $record): bool => app(IntegrationRegistry::class)->isMediaProvider($record->provider)
                    && $record->oauth_client_id !== null
                    && Gate::allows('update', $record))
                ->fillForm(fn (IntegrationConnection $record): array => [
                    'enterprise_ids' => DB::table('integration_enterprise_access')
                        ->where('oauth_client_id', $record->oauth_client_id)
                        ->where('active', true)
                        ->whereIn('enterprise_id', Enterprise::query()
                            ->where('organization_id', $record->organization_id)
                            ->select('id'))
                        ->pluck('enterprise_id')
                        ->map(fn ($id): int => (int) $id)
                        ->all(),
                ])
                ->form([
                    Select::make('enterprise_ids')
                        ->label('Authorized Enterprises')
                        ->multiple()
                        ->searchable()
                        ->options(fn (IntegrationConnection $record): array => Enterprise::query()
                            ->where('organization_id', $record->organization_id)
                            ->orderBy('name')
                            ->get()
                            ->filter(fn (Enterprise $enterprise): bool => Gate::allows('update', $enterprise))
                            ->mapWithKeys(fn (Enterprise $enterprise): array => [(int) $enterprise->id => $enterprise->name])
                            ->all())
                        ->helperText('Only selected Enterprises remain authorized. Saving does not affect other organizations.')
                        ->required(false),
                ])
                ->action(function (IntegrationConnection $record, array $data, IntegrationEnterpriseAccessService $access): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new AuthorizationException;
                    }

                    $access->syncForConnection($actor, $record, array_values($data['enterprise_ids'] ?? []));

                    Notification::make()
                        ->success()
                        ->title('Enterprise access updated')
                        ->send();
                }),
            Action::make('disconnect')
                ->requiresConfirmation()
                ->visible(fn (IntegrationConnection $record): bool => $record->social_identity_id !== null && $record->status !== IntegrationConnection::STATUS_REVOKED && Gate::allows('update', $record))
                ->action(function (IntegrationConnection $record, SocialAuthorizationService $authorization): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new AuthorizationException;
                    }

                    $result = $authorization->disconnect($actor, $record);
                    match ($result) {
                        SocialRevocationResult::Revoked => Notification::make()->success()->title('Social connection disconnected')->body('Remote revocation was confirmed.')->send(),
                        SocialRevocationResult::Unsupported => Notification::make()->warning()->title('Social connection disabled')->body('The provider does not support remote revocation.')->send(),
                        SocialRevocationResult::Failed => Notification::make()->warning()->title('Social connection disabled')->body('Remote revocation could not be confirmed.')->send(),
                    };
                }),
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