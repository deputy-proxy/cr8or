<?php

namespace App\Filament\Resources\Decisions;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Decisions\Pages\CreateDecision;
use App\Filament\Resources\Decisions\Pages\EditDecision;
use App\Filament\Resources\Decisions\Pages\ListDecisions;
use App\Models\Decision;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DecisionResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Decision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Decisions';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload()->required(),
            Select::make('type')->options(['operational' => 'Operational', 'strategic' => 'Strategic'])->required(),
            Select::make('actor_id')->options(fn () => \App\Models\User::query()->whereHas('memberships', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()))->pluck('name', 'id'))->searchable()->preload()->required(),
            Select::make('objective_id')->relationship('objective', 'name')->searchable()->preload(),
            Select::make('strategy_id')->relationship('strategy', 'name')->searchable()->preload(),
            Select::make('plan_id')->relationship('plan', 'name')->searchable()->preload(),
            Select::make('initiative_id')->relationship('initiative', 'name')->searchable()->preload(),
            Select::make('project_id')->relationship('project', 'name')->searchable()->preload(),
            Select::make('task_id')->relationship('task', 'name')->searchable()->preload(),
            Select::make('work_item_id')->relationship('workItem', 'name')->searchable()->preload(),
            TextInput::make('title')->required()->maxLength(255),
            Textarea::make('summary')->required()->rows(4),
            Textarea::make('rationale')->rows(4),
            DateTimePicker::make('decided_at')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('actor_name')->label('Actor')->searchable(),
            TextColumn::make('decided_at')->dateTime()->sortable(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
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
        return [
            'index' => ListDecisions::route('/'),
            'create' => CreateDecision::route('/create'),
            'edit' => EditDecision::route('/{record}/edit'),
        ];
    }
}
