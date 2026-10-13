<?php

namespace App\Filament\Resources\ContentItems;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\ContentItems\Pages\CreateContentItem;
use App\Filament\Resources\ContentItems\Pages\EditContentItem;
use App\Filament\Resources\ContentItems\Pages\ListContentItems;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\ContentItemService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContentItemResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = ContentItem::class;

    protected static ?string $modelLabel = 'Content Item';

    protected static ?string $pluralModelLabel = 'Content Items';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Content';

    protected static ?string $navigationLabel = 'Content Items';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload()->required(),
            Select::make('campaign_id')->relationship('campaign', 'name')->searchable()->preload()->required(),
            Select::make('content_series_id')->relationship('contentSeries', 'name')->searchable()->preload(),
            Select::make('channel_id')->relationship('channel', 'name')->searchable()->preload(),
            Select::make('audience_id')->relationship('audience', 'name')->searchable()->preload(),
            TextInput::make('title')->required()->maxLength(255),
            Textarea::make('body')->rows(10),
            Section::make('Threads metadata')
                ->description('Optional Threads topic and reply controls. Carousel posts and long-form text attachments are not enabled by this adapter.')
                ->collapsible()
                ->columns(2)
                ->schema([
                    TextInput::make('metadata.threads.topic_tag')->label('Topic tag')->maxLength(50)->helperText('Do not include a leading #.'),
                    Select::make('metadata.threads.reply_control')
                        ->label('Who can reply')
                        ->options(['everyone' => 'Everyone', 'accounts_you_follow' => 'Accounts you follow', 'mentioned_only' => 'Mentioned only'])
                        ->placeholder('Use Threads default'),
                ]),
            Section::make('YouTube metadata')
                ->description('Optional video-specific metadata. CR8OR defaults privacy to private when no override is supplied; made-for-kids is never inferred.')
                ->collapsible()
                ->columns(2)
                ->schema([
                    TextInput::make('metadata.youtube.title')->label('YouTube title override')->maxLength(100),
                    TextInput::make('metadata.youtube.category_id')->label('YouTube category ID')->numeric()->minValue(1)->maxValue(99999)->maxLength(5),
                    Textarea::make('metadata.youtube.description')->label('YouTube description override')->rows(4)->maxLength(5000)->columnSpanFull(),
                    TagsInput::make('metadata.youtube.tags')->label('YouTube tags')->columnSpanFull(),
                    Select::make('metadata.youtube.privacy_status')
                        ->label('Privacy')
                        ->options(['private' => 'Private', 'unlisted' => 'Unlisted', 'public' => 'Public'])
                        ->placeholder('Use the configured private default'),
                    Select::make('metadata.youtube.license')
                        ->label('License')
                        ->options(['youtube' => 'Standard YouTube License', 'creativeCommon' => 'Creative Commons'])
                        ->placeholder('Use YouTube default'),
                    Select::make('metadata.youtube.made_for_kids')
                        ->label('Made for kids')
                        ->options(['true' => 'Yes', 'false' => 'No'])
                        ->placeholder('Leave unset for YouTube to determine'),
                    Select::make('metadata.youtube.contains_synthetic_media')
                        ->label('Contains synthetic media')
                        ->options(['true' => 'Yes', 'false' => 'No'])
                        ->placeholder('Leave unset when not applicable'),
                    Toggle::make('metadata.youtube.notify_subscribers')->label('Notify subscribers')->default(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('campaign.name')->label('Campaign')->searchable(),
            TextColumn::make('contentSeries.name')->label('Series')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
            Action::make('submit_for_review')
                ->label('Submit for review')
                ->visible(fn (ContentItem $record): bool => $record->status === ContentItem::STATUS_DRAFT)
                ->action(function (ContentItem $record): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        abort(403);
                    }
                    app(ContentItemService::class)->submitForReview($actor, $record);
                }),
            Action::make('approve')
                ->label('Approve')
                ->visible(fn (ContentItem $record): bool => $record->status === ContentItem::STATUS_IN_REVIEW)
                ->action(function (ContentItem $record): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        abort(403);
                    }
                    app(ContentItemService::class)->approve($actor, $record);
                }),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
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
        return ['index' => ListContentItems::route('/'), 'create' => CreateContentItem::route('/create'), 'edit' => EditContentItem::route('/{record}/edit')];
    }
}