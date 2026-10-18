<?php

namespace App\Filament\Resources\Competitors;

use App\Filament\Resources\Competitors\Pages\CreateCompetitor;
use App\Filament\Resources\Competitors\Pages\EditCompetitor;
use App\Filament\Resources\Competitors\Pages\ListCompetitors;
use App\Filament\Resources\Competitors\Pages\ViewCompetitor;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Models\Competitor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\Gate;

class CompetitorResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Competitor::class;

    protected static ?string $modelLabel = 'Competitor';

    protected static ?string $pluralModelLabel = 'Competitors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Enterprises & Products';

    protected static ?string $navigationLabel = 'Competitors';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', modifyQueryUsing: fn (EloquentBuilder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()))->searchable()->preload()->required()->disabledOn('edit')->dehydrated(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('website')->url()->maxLength(255),
            Textarea::make('positioning')->rows(4),
            TagsInput::make('strengths'),
            TagsInput::make('weaknesses'),
            self::profileBuilder('products', 'Products and services', 'product', [
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('description')->rows(2)->maxLength(5000),
                TextInput::make('category')->maxLength(255),
                TagsInput::make('features'),
                TextInput::make('target_segment')->maxLength(500),
                TagsInput::make('differentiators'),
                TextInput::make('pricing_reference')->maxLength(255),
            ]),
            self::profileBuilder('target_segments', 'Target segments', 'segment', [
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('description')->rows(2)->maxLength(5000),
                TextInput::make('industry')->maxLength(255),
                Textarea::make('demographics')->rows(2)->maxLength(2000),
                TagsInput::make('needs'),
                TagsInput::make('use_cases'),
            ]),
            self::profileBuilder('pricing', 'Pricing', 'price', [
                TextInput::make('model')->required()->maxLength(100),
                TextInput::make('product_name')->maxLength(255),
                TextInput::make('amount')->numeric()->minValue(0),
                TextInput::make('minimum_amount')->numeric()->minValue(0),
                TextInput::make('maximum_amount')->numeric()->minValue(0),
                TextInput::make('currency')->maxLength(3),
                TextInput::make('billing_interval')->maxLength(100),
                Textarea::make('terms')->rows(2)->maxLength(2000),
                TextInput::make('package')->maxLength(255),
            ]),
            self::profileBuilder('markets', 'Markets', 'market', [
                TextInput::make('geography')->required()->maxLength(255),
                TagsInput::make('industries'),
                TagsInput::make('languages'),
                Textarea::make('notes')->rows(2)->maxLength(2000),
            ]),
            self::profileBuilder('differentiators', 'Differentiators', 'differentiator', [
                Textarea::make('claim')->required()->rows(2)->maxLength(2000),
                Select::make('claim_type')->options([
                    'competitor_claim' => 'Competitor claim',
                    'observed_fact' => 'Observed fact',
                    'verified_fact' => 'Verified fact',
                ])->required(),
                TextInput::make('category')->maxLength(255),
            ]),
            self::profileBuilder('channels', 'Sales and acquisition channels', 'channel', [
                Select::make('channel_type')->options([
                    'sales' => 'Sales', 'distribution' => 'Distribution', 'acquisition' => 'Acquisition',
                    'social' => 'Social', 'other' => 'Other',
                ])->required(),
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('details')->rows(2)->maxLength(2000),
            ]),
            self::profileBuilder('reputation', 'Reputation observations', 'observation', [
                TextInput::make('platform')->required()->maxLength(255),
                TextInput::make('rating')->numeric()->minValue(0),
                TextInput::make('rating_scale')->maxLength(100),
                TextInput::make('review_count')->numeric()->minValue(0),
                Select::make('sentiment')->options([
                    'positive' => 'Positive', 'negative' => 'Negative', 'mixed' => 'Mixed', 'unknown' => 'Unknown',
                ])->required(),
                Textarea::make('summary')->rows(2)->maxLength(2000),
                DatePicker::make('observed_at'),
            ]),
            self::profileBuilder('digital_presence', 'Digital presence', 'presence', [
                TextInput::make('channel')->required()->maxLength(255),
                TextInput::make('url')->url()->maxLength(2048),
                Textarea::make('observation')->required()->rows(2)->maxLength(2000),
                DatePicker::make('observed_at'),
            ]),
            self::profileBuilder('source_references', 'Knowledge evidence references', 'source', [
                TextInput::make('knowledge_reference_id')->numeric()->minValue(1)->required(),
                TextInput::make('field')->maxLength(100),
                Textarea::make('claim')->rows(2)->maxLength(1000),
                DatePicker::make('observed_at'),
            ]),
        ]);
    }

    /**
     * Builder gives editors a schema-driven interface while persisting a stable list of data objects.
     * The stored contract is independent of Filament's internal type/data wrapper.
     *
     * @param  array<int, \Filament\Schemas\Components\Component>  $fields
     */
    private static function profileBuilder(string $name, string $label, string $block, array $fields): Builder
    {
        return Builder::make($name)
            ->label($label)
            ->blocks([Block::make($block)->label($label)->schema($fields)])
            ->default([])
            ->afterStateHydrated(function (Builder $component): void {
                $state = $component->getRawState();
                if (is_array($state) && array_is_list($state) && isset($state[0]) && is_array($state[0]) && ! array_key_exists('type', $state[0])) {
                    $blockName = $component->getBlocks()[0]->getName();
                    $component->state(array_map(static fn (array $item): array => ['type' => $blockName, 'data' => $item], $state));
                    $component->hydrateItems();
                }
            })
            ->dehydrateStateUsing(fn (?array $state): array => collect($state ?? [])->map(fn (array $item): array => $item['data'] ?? [])->values()->all())
            ->collapsible();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('version')->sortable(),
            TextColumn::make('status')->badge(),
        ])->filters([
            SelectFilter::make('status')
                ->options([
                    Competitor::STATUS_ACTIVE => 'Active',
                    Competitor::STATUS_ARCHIVED => 'Archived',
                ])
                ->default(Competitor::STATUS_ACTIVE),
        ])->groups([
            Group::make('enterprise_id')
                ->label('Enterprise')
                ->getKeyFromRecordUsing(fn (Competitor $record): int|string => $record->enterprise_id ?? 'ungrouped')
                ->getTitleFromRecordUsing(fn (Competitor $record): string => $record->enterprise->name ?? 'Ungrouped')
                ->titlePrefixedWithLabel(false)
                ->collapsible(),
        ])->defaultGroup('enterprise_id')->recordActions([
            ViewAction::make(),
            EditAction::make()->visible(fn (Competitor $record): bool => Gate::allows('update', $record)),
            Action::make('archive')
                ->label('Archive')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Competitor $record): bool => Gate::allows('archive', $record))
                ->action(fn (Competitor $record) => app(\App\Services\StrategicContextService::class)->archiveCompetitor(auth()->user(), $record)),
        ]);
    }

    public static function getEloquentQuery(): EloquentBuilder
    {
        return parent::getEloquentQuery()
            ->with('enterprise')
            ->whereHas('enterprise', fn (EloquentBuilder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompetitors::route('/'),
            'create' => CreateCompetitor::route('/create'),
            'view' => ViewCompetitor::route('/{record}'),
            'edit' => EditCompetitor::route('/{record}/edit'),
        ];
    }
}
