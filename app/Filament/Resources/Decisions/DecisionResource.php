<?php

namespace App\Filament\Resources\Decisions;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Decisions\Pages\CreateDecision;
use App\Filament\Resources\Decisions\Pages\EditDecision;
use App\Filament\Resources\Decisions\Pages\ListDecisions;
use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DecisionResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Decision::class;

    protected static ?string $modelLabel = 'Decision';

    protected static ?string $pluralModelLabel = 'Decisions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Strategy & Execution Planning';

    protected static ?string $navigationLabel = 'Decisions';

    protected static ?int $navigationSort = 120;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->options(fn (): array => Enterprise::query()
                    ->whereIn('id', static::manageableEnterpriseIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->live(),
            Select::make('type')->options(['operational' => 'Operational', 'strategic' => 'Strategic'])->required(),
            Select::make('actor_id')
                ->options(fn (Get $get): array => $get('enterprise_id')
                    ? User::query()
                        ->whereHas('memberships', fn (Builder $query) => $query->whereIn(
                            'organization_id',
                            Enterprise::query()->whereKey($get('enterprise_id'))->whereIn('id', static::manageableEnterpriseIds())->select('organization_id'),
                        ))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()
                    : [])
                ->searchable()
                ->required(),
            Select::make('objective_id')
                ->options(fn (Get $get): array => $get('enterprise_id')
                    ? Objective::query()->where('enterprise_id', $get('enterprise_id'))->whereIn('enterprise_id', static::manageableEnterpriseIds())->orderBy('name')->pluck('name', 'id')->all()
                    : [])
                ->searchable(),
            Select::make('strategy_id')
                ->options(fn (Get $get): array => $get('enterprise_id')
                    ? Strategy::query()->whereHas('objective', fn (Builder $query) => $query->where('enterprise_id', $get('enterprise_id'))->whereIn('enterprise_id', static::manageableEnterpriseIds()))->orderBy('name')->pluck('name', 'id')->all()
                    : [])
                ->searchable(),
            Select::make('plan_id')
                ->options(fn (Get $get): array => $get('enterprise_id')
                    ? Plan::query()->whereHas('strategy.objective', fn (Builder $query) => $query->where('enterprise_id', $get('enterprise_id'))->whereIn('enterprise_id', static::manageableEnterpriseIds()))->orderBy('name')->pluck('name', 'id')->all()
                    : [])
                ->searchable(),
            Select::make('initiative_id')
                ->options(fn (Get $get): array => $get('enterprise_id')
                    ? Initiative::query()->whereHas('plan.strategy.objective', fn (Builder $query) => $query->where('enterprise_id', $get('enterprise_id'))->whereIn('enterprise_id', static::manageableEnterpriseIds()))->orderBy('name')->pluck('name', 'id')->all()
                    : [])
                ->searchable(),
            Select::make('project_id')
                ->options(fn (Get $get): array => $get('enterprise_id')
                    ? Project::query()->where('enterprise_id', $get('enterprise_id'))->whereIn('enterprise_id', static::manageableEnterpriseIds())->orderBy('name')->pluck('name', 'id')->all()
                    : [])
                ->searchable()
                ->live(),
            Select::make('task_id')
                ->options(fn (Get $get): array => $get('enterprise_id')
                    ? Task::query()
                        ->where('enterprise_id', $get('enterprise_id'))
                        ->whereIn('enterprise_id', static::manageableEnterpriseIds())
                        ->when($get('project_id'), fn (Builder $query, mixed $projectId) => $query->where('project_id', $projectId))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()
                    : [])
                ->searchable(),
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
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('create', static::getModel());
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
