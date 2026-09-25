<?php

namespace App\Filament\Resources\ContentSeries;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\ContentSeries\Pages\CreateContentSeries;
use App\Filament\Resources\ContentSeries\Pages\EditContentSeries;
use App\Filament\Resources\ContentSeries\Pages\ListContentSeries;
use App\Models\ContentSeries;
use App\Models\User;
use App\Services\DomainResourceService;
use BackedEnum;
use Filament\Actions\Action;
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

class ContentSeriesResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = ContentSeries::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Series';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('campaign_id')->relationship('campaign', 'name', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
            Select::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'completed' => 'Completed', 'archived' => 'Archived'])->default('draft')->required()->disabled(fn (?ContentSeries $record): bool => $record !== null)->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('campaign.name')->label('Campaign')->searchable()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
        ])->recordActions([
            EditAction::make(),
            Action::make('transition')->label('Change status')->form([
                Select::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'completed' => 'Completed', 'archived' => 'Archived'])->required(),
            ])->action(function (ContentSeries $record, array $data): void {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    abort(403);
                }
                app(DomainResourceService::class)->transitionContentSeries($actor, $record, $data['status']);
            }),
            DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('campaign.enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }


    public static function getPages(): array
    {
        return ['index' => ListContentSeries::route('/'), 'create' => CreateContentSeries::route('/create'), 'edit' => EditContentSeries::route('/{record}/edit')];
    }
}
