<?php

namespace App\Filament\Resources\Workflows;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Workflows\Pages\CreateWorkflow;
use App\Filament\Resources\Workflows\Pages\EditWorkflow;
use App\Filament\Resources\Workflows\Pages\ListWorkflows;
use App\Models\Enterprise;
use App\Models\Workflow;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkflowResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Workflow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflow Flow';

    protected static ?string $navigationLabel = 'Workflows';

    protected static ?int $navigationSort = 70;

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

                    TagsInput::make('expert_slugs')
                        ->label('Expert slugs'),

                    TagsInput::make('capability_slugs')
                        ->label('Capability slugs'),

                    Toggle::make('repeatable')
                        ->default(false),

                    Textarea::make('input_contract')
                        ->rows(3)
                        ->formatStateUsing(fn ($state): string => is_array($state) ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : (string) ($state ?? ''))
                        ->dehydrateStateUsing(fn ($state): array => is_string($state) && trim($state) !== '' ? (json_decode($state, true, 512, JSON_THROW_ON_ERROR) ?: []) : []),

                    Textarea::make('output_contract')
                        ->rows(3)
                        ->formatStateUsing(fn ($state): string => is_array($state) ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : (string) ($state ?? ''))
                        ->dehydrateStateUsing(fn ($state): array => is_string($state) && trim($state) !== '' ? (json_decode($state, true, 512, JSON_THROW_ON_ERROR) ?: []) : []),
                ])
                ->columns(2),
        ]);
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
