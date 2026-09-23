<?php

namespace App\Filament\Resources\ContentItems;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\ContentItems\Pages\CreateContentItem;
use App\Filament\Resources\ContentItems\Pages\EditContentItem;
use App\Filament\Resources\ContentItems\Pages\ListContentItems;
use App\Models\ContentItem;
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

class ContentItemResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = ContentItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('campaign_id')->relationship('campaign', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('content_series_id')->relationship('contentSeries', 'name', fn (Builder $q) => $q->whereHas('campaign', fn (Builder $c) => $c->whereIn('enterprise_id', static::manageableEnterpriseIds())))->searchable()->preload(),
            Select::make('channel_id')->relationship('channel', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload(),
            Select::make('audience_id')->relationship('audience', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload(),
            TextInput::make('title')->required()->maxLength(255), Textarea::make('body')->rows(10),
            Select::make('status')->options(['draft' => 'Draft', 'in_review' => 'In review', 'approved' => 'Approved', 'publication_ready' => 'Publication ready', 'archived' => 'Archived'])->default('draft')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable()->sortable(), TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(), TextColumn::make('campaign.name')->label('Campaign')->searchable(), TextColumn::make('contentSeries.name')->label('Series')->searchable(), TextColumn::make('status')->badge()->sortable()])->recordActions([EditAction::make(), DeleteAction::make()]);
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
        return ['index' => ListContentItems::route('/'), 'create' => CreateContentItem::route('/create'), 'edit' => EditContentItem::route('/{record}/edit')];
    }
}
