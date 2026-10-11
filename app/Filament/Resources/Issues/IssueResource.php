<?php

namespace App\Filament\Resources\Issues;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Issues\Pages\ListIssues;
use App\Models\Enterprise;
use App\Models\Issue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class IssueResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Issue::class;

    protected static ?string $modelLabel = 'GitHub Issue';

    protected static ?string $pluralModelLabel = 'GitHub Issues';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'Issues';

    protected static ?int $navigationSort = 60;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('#')->sortable(),
                TextColumn::make('title')->searchable()->wrap()->limit(100)
                    ->url(fn (Issue $record): string => $record->url, true),
                TextColumn::make('repository')->searchable()->sortable(),
                TextColumn::make('state')->badge()->color(fn (string $state): string => $state === 'open' ? 'success' : 'gray')->sortable(),
                TextColumn::make('labels')->badge()->separator(',')->toggleable(),
                TextColumn::make('author_login')->label('Author')->searchable()->sortable(),
                TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
                TextColumn::make('github_updated_at')->label('GitHub Updated')->dateTime()->sortable(),
                TextColumn::make('last_synced_at')->label('Last Synced')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->options([
                    'open' => 'Open',
                    'closed' => 'Closed',
                ]),
                SelectFilter::make('enterprise_id')
                    ->label('Enterprise')
                    ->options(fn (): array => Enterprise::query()
                        ->whereIn('organization_id', static::authorizedOrganizationIds())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->defaultSort('github_updated_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('enterprise', fn (Builder $query) => $query
                ->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && Gate::allows('viewAny', static::getModel());
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIssues::route('/'),
        ];
    }
}