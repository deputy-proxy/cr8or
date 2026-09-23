<?php

namespace App\Filament\Resources\Publications;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Publications\Pages\CreatePublication;
use App\Filament\Resources\Publications\Pages\EditPublication;
use App\Filament\Resources\Publications\Pages\ListPublications;
use App\Models\Publication;
use BackedEnum;
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

class PublicationResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Publication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('content_item_id')->relationship('contentItem', 'title', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('channel_id')->relationship('channel', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('social_account_id')->relationship('socialAccount', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('approval_request_id')->relationship('approvalRequest', 'id')->searchable()->preload(),
            Select::make('status')->options(['scheduled' => 'Scheduled', 'submitted' => 'Submitted', 'succeeded' => 'Succeeded', 'failed' => 'Failed'])->required(),
            TextInput::make('external_id')->disabled()->maxLength(255),
            TextInput::make('external_url')->disabled()->url()->maxLength(2048),
            TextInput::make('scheduled_at')->disabled(),
            TextInput::make('submitted_at')->disabled()->disabled(),
            TextInput::make('published_at')->disabled()->disabled(),
            TextInput::make('failure_code')->maxLength(255),
            Textarea::make('failure_reason')->rows(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('contentItem.title')->searchable()->sortable(),
            TextColumn::make('channel.name')->searchable()->sortable(),
            TextColumn::make('socialAccount.name')->searchable()->sortable(),
            TextColumn::make('approvalRequest.id')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('external_id')->searchable()->sortable(),
            TextColumn::make('external_url')->searchable()->sortable(),
        ])->recordActions([
            EditAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('enterprise_id', static::authorizedEnterpriseIds());
    }

    /** @return Builder<\App\Models\Enterprise> */
    protected static function authorizedEnterpriseIds(): Builder
    {
        return \App\Models\Enterprise::query()->select('enterprises.id')->whereIn('organization_id', static::authorizedOrganizationIds());
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
        return ['index' => ListPublications::route('/'), 'create' => CreatePublication::route('/create'), 'edit' => EditPublication::route('/{record}/edit')];
    }
}