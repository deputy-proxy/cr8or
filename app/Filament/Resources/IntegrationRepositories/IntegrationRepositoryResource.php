<?php

namespace App\Filament\Resources\IntegrationRepositories;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\IntegrationRepositories\Pages\ListIntegrationRepositories;
use App\Jobs\SyncIntegrationRepositoryIssues;
use App\Models\Enterprise;
use App\Models\IntegrationRepository;
use App\Models\User;
use App\Services\IntegrationRepositoryAssignmentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class IntegrationRepositoryResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = IntegrationRepository::class;

    protected static ?string $modelLabel = 'GitHub Repository';

    protected static ?string $pluralModelLabel = 'GitHub Repositories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflows & Integrations';

    protected static ?string $navigationLabel = 'GitHub Repositories';

    protected static ?int $navigationSort = 55;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('full_name')->label('Repository')->searchable()->sortable()->url(fn (IntegrationRepository $record): string => $record->url, true),
            TextColumn::make('connection.organization.name')->label('Organization')->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->placeholder('Unassigned')->sortable(),
            TextColumn::make('is_private')->label('Visibility')->formatStateUsing(fn (bool $state): string => $state ? 'Private' : 'Public')->badge(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('sync_status')->label('Sync')->badge()->sortable(),
            TextColumn::make('last_synced_at')->dateTime()->sortable(),
            TextColumn::make('last_sync_error')->limit(60)->toggleable(),
        ])->filters([
            SelectFilter::make('enterprise_id')->label('Enterprise')->options(fn (): array => Enterprise::query()->whereIn('organization_id', static::authorizedOrganizationIds())->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('status')->options(['active' => 'Active', 'inaccessible' => 'Inaccessible', 'disabled' => 'Disabled']),
        ])->recordActions([
            Action::make('resync')->label('Synchronize issues')->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (IntegrationRepository $record): bool => $record->enterprise_id !== null && $record->status === IntegrationRepository::STATUS_ACTIVE && Gate::allows('update', $record))
                ->requiresConfirmation()
                ->action(function (IntegrationRepository $record): void {
                    SyncIntegrationRepositoryIssues::dispatch((int) $record->id);
                    Notification::make()->success()->title('Issue synchronization queued')->send();
                }),
            Action::make('assign')->label('Assign Enterprise')->icon(Heroicon::OutlinedBuildingOffice)
                ->visible(fn (IntegrationRepository $record): bool => $record->enterprise_id === null && $record->status === IntegrationRepository::STATUS_ACTIVE && Gate::allows('update', $record))
                ->fillForm(fn (IntegrationRepository $record): array => ['enterprise_id' => $record->enterprise_id])
                ->form(fn (IntegrationRepository $record): array => [Select::make('enterprise_id')->label('Enterprise')->options(fn (): array => Enterprise::query()->where('organization_id', $record->organization_id)->whereIn('organization_id', static::authorizedOrganizationIds())->orderBy('name')->pluck('name', 'id')->all())->required()->searchable()])
                ->action(function (IntegrationRepository $record, array $data, IntegrationRepositoryAssignmentService $assignment): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new AuthorizationException;
                    }
                    $enterprise = Enterprise::query()->whereKey((int) $data['enterprise_id'])->where('organization_id', $record->organization_id)->firstOrFail();
                    $assignment->assign($actor, $record, $enterprise);
                    Notification::make()->success()->title('Repository assigned')->send();
                }),
            Action::make('unassign')->label('Unassign')->color('gray')->requiresConfirmation()
                ->visible(fn (IntegrationRepository $record): bool => $record->enterprise_id !== null && Gate::allows('update', $record))
                ->action(function (IntegrationRepository $record, IntegrationRepositoryAssignmentService $assignment): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new AuthorizationException;
                    }
                    $assignment->unassign($actor, $record);
                    Notification::make()->success()->title('Repository unassigned')->send();
                }),
        ])->defaultSort('full_name');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && Gate::allows('viewAny', static::getModel());
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListIntegrationRepositories::route('/')];
    }
}
