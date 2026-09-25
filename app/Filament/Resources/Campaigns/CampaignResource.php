<?php

namespace App\Filament\Resources\Campaigns;

use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\Campaign;
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

class CampaignResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Campaigns';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload()->required(),
            Select::make('marketing_strategy_id')->relationship('marketingStrategy', 'name')->searchable()->preload()->required(),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(4),
            Select::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused', 'completed' => 'Completed', 'archived' => 'Archived'])->default('draft')->required()->disabled(fn (?Campaign $record): bool => $record !== null)->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('marketingStrategy.name')->label('Strategy')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
        ])->recordActions([
            EditAction::make(),
            Action::make('transition')->label('Change status')->form([
                Select::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused', 'completed' => 'Completed', 'archived' => 'Archived'])->required(),
            ])->action(function (Campaign $record, array $data): void {
                $actor = auth()->user();
                if (! $actor instanceof User) {
                    abort(403);
                }
                app(DomainResourceService::class)->transitionCampaign($actor, $record, $data['status']);
            }),
            DeleteAction::make(),
        ]);
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
        return ['index' => ListCampaigns::route('/'), 'create' => CreateCampaign::route('/create'), 'edit' => EditCampaign::route('/{record}/edit')];
    }
}
