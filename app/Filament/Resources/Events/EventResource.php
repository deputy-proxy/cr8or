<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class EventResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Event::class;

    protected static ?string $modelLabel = 'Event';

    protected static ?string $pluralModelLabel = 'Events';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Events';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('Occurred')->dateTime()->sortable(),
                TextColumn::make('description')->searchable()->wrap()->limit(100),
                TextColumn::make('event_type')->label('Type')->badge()->searchable()->sortable(),
                TextColumn::make('source')->badge()->sortable(),
                TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
                TextColumn::make('organization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('actor.name')->label('Actor')->placeholder('System')->searchable(),
                TextColumn::make('correlation_id')->label('Correlation ID')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('received_at')->label('Recorded')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('source')->options([
                    Event::SOURCE_CR8OR => 'CR8OR',
                    Event::SOURCE_GTM => 'Website (GTM)',
                ]),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('events.organization_id', static::authorizedOrganizationIds())
            ->whereHas('enterprise', fn (Builder $query) => $query
                ->whereColumn('enterprises.organization_id', 'events.organization_id'));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && Gate::allows('viewAny', static::getModel());
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
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
            'index' => ListEvents::route('/'),
        ];
    }
}