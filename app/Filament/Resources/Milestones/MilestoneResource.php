<?php

namespace App\Filament\Resources\Milestones;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Milestones\Pages\CreateMilestone;
use App\Filament\Resources\Milestones\Pages\EditMilestone;
use App\Filament\Resources\Milestones\Pages\ListMilestones;
use App\Models\Enterprise;
use App\Models\Milestone;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MilestoneResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Milestone::class;

    protected static ?string $modelLabel = 'Milestone';

    protected static ?string $pluralModelLabel = 'Milestones';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|\UnitEnum|null $navigationGroup = 'Strategy & Execution Planning';

    protected static ?string $navigationLabel = 'Milestones';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->options(fn (): array => Enterprise::query()->whereIn('id', static::manageableEnterpriseIds())->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
            TextInput::make('name')->required()->maxLength(255), Textarea::make('description')->rows(4),
            DatePicker::make('target_date')->label('Target achievement date'),
            Select::make('status')->options(['planned' => 'Planned', 'active' => 'Active', 'completed' => 'Completed'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(), TextColumn::make('target_date')->date()->sortable(), TextColumn::make('status')->badge()])->recordActions([\Filament\Actions\EditAction::make(), \Filament\Actions\DeleteAction::make()]);
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
        return ['index' => ListMilestones::route('/'), 'create' => CreateMilestone::route('/create'), 'edit' => EditMilestone::route('/{record}/edit')];
    }
}
