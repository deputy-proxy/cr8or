<?php

namespace App\Filament\Resources\PublicationSchedules;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\PublicationSchedules\Pages\CreatePublicationSchedule;
use App\Filament\Resources\PublicationSchedules\Pages\EditPublicationSchedule;
use App\Filament\Resources\PublicationSchedules\Pages\ListPublicationSchedules;
use App\Models\PublicationSchedule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PublicationScheduleResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = PublicationSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            Select::make('publication_id')->relationship('publication', 'id', fn (Builder $q) => $q->whereIn('enterprise_id', static::manageableEnterpriseIds()))->searchable()->preload()->required(),
            TextInput::make('scheduled_at')->disabled(),
            Select::make('status')->options(['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled', 'completed' => 'Completed'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('publication.id')->searchable()->sortable(),
            TextColumn::make('scheduled_at')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
        ])->recordActions([
            EditAction::make(), DeleteAction::make(),
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
        return ['index' => ListPublicationSchedules::route('/'), 'create' => CreatePublicationSchedule::route('/create'), 'edit' => EditPublicationSchedule::route('/{record}/edit')];
    }
}