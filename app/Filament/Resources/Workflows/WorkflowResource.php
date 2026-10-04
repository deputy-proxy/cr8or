<?php

namespace App\Filament\Resources\Workflows;

use App\Experts\ExpertRegistry;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Workflows\Pages\CreateWorkflow;
use App\Filament\Resources\Workflows\Pages\EditWorkflow;
use App\Filament\Resources\Workflows\Pages\ListWorkflows;
use App\Models\Enterprise;
use App\Models\Workflow;
use App\Services\ExpertCapabilityResolver;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkflowResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Workflow::class;

    protected static ?string $modelLabel = 'Workflow';

    protected static ?string $pluralModelLabel = 'Workflows';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflow Flow';

    protected static ?string $navigationLabel = 'Workflows';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->label('Enterprise')
                ->options(fn (): array => Enterprise::query()
                    ->whereIn('organization_id', static::manageableOrganizationIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(),

            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('canonical_key')
                ->label('Canonical key')
                ->maxLength(150)
                ->regex('/^[a-z0-9][a-z0-9._-]*$/')
                ->helperText('Optional stable identifier, for example strategy.create.'),

            Textarea::make('purpose')
                ->rows(3)
                ->maxLength(10000),

            Select::make('status')
                ->options([
                    Workflow::STATUS_PENDING => 'Pending',
                    Workflow::STATUS_RUNNING => 'Running',
                    Workflow::STATUS_SUCCEEDED => 'Succeeded',
                    Workflow::STATUS_FAILED => 'Failed',
                ])
                ->required(),

            Textarea::make('execution_policy')
                ->rows(5)
                ->formatStateUsing(fn ($state): string => is_array($state) ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : (string) ($state ?? ''))
                ->dehydrateStateUsing(fn ($state): array => is_string($state) && trim($state) !== '' ? (json_decode($state, true, 512, JSON_THROW_ON_ERROR) ?: []) : [])
                ->helperText('JSON object controlling execution mode and provider requirements.'),

            Textarea::make('completion_criteria')
                ->rows(5)
                ->formatStateUsing(fn ($state): string => is_array($state) ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : (string) ($state ?? ''))
                ->dehydrateStateUsing(fn ($state): array => is_string($state) && trim($state) !== '' ? (json_decode($state, true, 512, JSON_THROW_ON_ERROR) ?: []) : [])
                ->helperText('JSON object describing when the workflow is complete.'),

            Repeater::make('stages')
                ->relationship()
                ->label('Stages')
                ->defaultItems(1)
                ->addActionLabel('Add stage')
                ->orderColumn('sequence')
                ->schema([
                    TextInput::make('key')
                        ->required()
                        ->maxLength(150)
                        ->regex('/^[a-z0-9][a-z0-9._-]*$/'),

                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('sequence')
                        ->numeric()
                        ->required()
                        ->default(1),

                    Select::make('expert_slugs')
                        ->label('Expert')
                        ->options(fn (): array => app(ExpertRegistry::class)->options())
                        ->formatStateUsing(fn ($state): ?string => is_array($state) ? ($state[0] ?? null) : (filled($state) ? (string) $state : null))
                        ->dehydrateStateUsing(fn ($state): array => filled($state) ? [(string) $state] : [])
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('capability_slugs', null);
                            $set('capability_input_contract', []);
                            $set('capability_output_contract', []);
                        }),

                    Select::make('capability_slugs')
                        ->label('Capability')
                        ->options(function (Get $get): array {
                            $expert = $get('expert_slugs');
                            $expert = is_array($expert) ? ($expert[0] ?? null) : $expert;

                            return is_string($expert) && $expert !== ''
                                ? app(ExpertCapabilityResolver::class)->capabilityOptions($expert)
                                : [];
                        })
                        ->formatStateUsing(fn ($state): ?string => is_array($state) ? ($state[0] ?? null) : (filled($state) ? (string) $state : null))
                        ->dehydrateStateUsing(fn ($state): array => filled($state) ? [(string) $state] : [])
                        ->searchable()
                        ->disabled(fn (Get $get): bool => blank($get('expert_slugs')))
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                            $expert = $get('expert_slugs');
                            $expert = is_array($expert) ? ($expert[0] ?? null) : $expert;

                            if (! is_string($expert) || $expert === '' || ! is_string($state) || $state === '') {
                                $set('capability_input_contract', []);
                                $set('capability_output_contract', []);

                                return;
                            }

                            $definition = app(ExpertCapabilityResolver::class)->resolve($expert, $state);
                            $set(
                                'capability_input_contract',
                                static::formatJsonContract($definition->inputContract),
                            );
                            $set(
                                'capability_output_contract',
                                static::formatJsonContract($definition->outputContract),
                            );
                            $set(
                                'input_contract',
                                static::formatJsonContract(
                                    static::workflowInputContract($definition->inputContract, $get('input_contract')),
                                ),
                            );
                        }),

                    Textarea::make('capability_input_contract')
                        ->label('Capability Input Contract')
                        ->rows(5)
                        ->disabled()
                        ->dehydrated()
                        ->formatStateUsing(fn ($state, Get $get): string => static::formatCapabilityContract($state, $get, true))
                        ->dehydrateStateUsing(fn ($state): array => is_string($state) && trim($state) !== '' ? (json_decode($state, true, 512, JSON_THROW_ON_ERROR) ?: []) : [])
                        ->helperText('Read-only. Derived from the selected CapabilityRegistry definition.'),

                    Textarea::make('capability_output_contract')
                        ->label('Capability Output Contract')
                        ->rows(5)
                        ->disabled()
                        ->dehydrated()
                        ->formatStateUsing(fn ($state, Get $get): string => static::formatCapabilityContract($state, $get, false))
                        ->dehydrateStateUsing(fn ($state): array => is_string($state) && trim($state) !== '' ? (json_decode($state, true, 512, JSON_THROW_ON_ERROR) ?: []) : [])
                        ->helperText('Read-only. Derived from the selected CapabilityRegistry definition.'),

                    Toggle::make('repeatable')
                        ->default(false),

                    Textarea::make('input_contract')
                        ->label('Workflow Input Mapping / Defaults')
                        ->rows(4)
                        ->formatStateUsing(fn ($state): string => is_array($state) ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : (string) ($state ?? ''))
                        ->dehydrateStateUsing(fn ($state): array => is_string($state) && trim($state) !== '' ? (json_decode($state, true, 512, JSON_THROW_ON_ERROR) ?: []) : [])
                        ->helperText('Workflow orchestration settings such as required inputs, defaults, and stage mappings. Kept separate from the canonical Capability contract.'),

                    Textarea::make('output_contract')
                        ->label('Workflow Output Contract')
                        ->rows(4)
                        ->formatStateUsing(fn ($state): string => is_array($state) ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : (string) ($state ?? ''))
                        ->dehydrateStateUsing(fn ($state): array => is_string($state) && trim($state) !== '' ? (json_decode($state, true, 512, JSON_THROW_ON_ERROR) ?: []) : [])
                        ->helperText('Workflow-level output/completion configuration. The canonical Capability output contract is shown above.'),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    public static function formatJsonContract(array $contract): string
    {
        return (string) json_encode(
            $contract,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Build the Workflow orchestration input contract from the canonical Capability input contract.
     *
     * The Capability contract answers what the Capability accepts. The Workflow contract answers
     * how those values are supplied at execution time, so defaults and mappings remain editable.
     *
     * @param  array<string, string>  $capabilityInputContract
     * @return array<string, mixed>
     */
    public static function workflowInputContract(array $capabilityInputContract, mixed $currentState = null): array
    {
        $existing = [];

        if (is_array($currentState)) {
            $existing = $currentState;
        } elseif (is_string($currentState) && trim($currentState) !== '') {
            try {
                $decoded = json_decode($currentState, true, 512, JSON_THROW_ON_ERROR);
                $existing = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $existing = [];
            }
        }

        $required = [];
        foreach ($capabilityInputContract as $key => $rule) {
            $rules = explode('|', $rule);
            if (in_array('required', $rules, true)) {
                $required[] = $key;
            }
        }

        $existing['required'] = $required;
        $existing['defaults'] = is_array($existing['defaults'] ?? null)
            ? $existing['defaults']
            : [];
        $existing['mappings'] = is_array($existing['mappings'] ?? null)
            ? $existing['mappings']
            : [];

        return $existing;
    }

    public static function formatCapabilityContract(mixed $state, Get $get, bool $input): string
    {
        $contract = is_array($state) && $state !== [] ? $state : null;

        if ($contract === null) {
            $expert = $get('expert_slugs');
            $expert = is_array($expert) ? ($expert[0] ?? null) : $expert;
            $capability = $get('capability_slugs');
            $capability = is_array($capability) ? ($capability[0] ?? null) : $capability;

            if (is_string($expert) && $expert !== '' && is_string($capability) && $capability !== '') {
                $definition = app(ExpertCapabilityResolver::class)->resolve($expert, $capability);
                $contract = $input ? $definition->inputContract : $definition->outputContract;
            }
        }

        return is_array($contract)
            ? static::formatJsonContract($contract)
            : (string) ($state ?? '');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('canonical_key')->label('Canonical key')->searchable()->sortable(),
                TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
                TextColumn::make('publishedVersion.version')->label('Published version')->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas(
            'enterprise',
            fn (Builder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()),
        );
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
        return [
            'index' => ListWorkflows::route('/'),
            'create' => CreateWorkflow::route('/create'),
            'edit' => EditWorkflow::route('/{record}/edit'),
        ];
    }
}