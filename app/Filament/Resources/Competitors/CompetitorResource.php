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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
            Select::make('enterprise_id')->relationship('enterprise', 'name', modifyQueryUsing: fn (Builder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()))->searchable()->preload()->required()->disabledOn('edit')->dehydrated(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('website')->url()->maxLength(255),
            Textarea::make('positioning')->rows(4),
            TagsInput::make('strengths'),
            TagsInput::make('weaknesses'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('version')->sortable(),
            TextColumn::make('status')->badge(),
        ])->recordActions([
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
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
