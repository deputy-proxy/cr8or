<?php

namespace App\Filament\Resources\EnterpriseDecisions;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\EnterpriseDecisions\Pages\CreateEnterpriseDecision;
use App\Filament\Resources\EnterpriseDecisions\Pages\EditEnterpriseDecision;
use App\Filament\Resources\EnterpriseDecisions\Pages\ListEnterpriseDecisions;
use App\Models\EnterpriseDecision;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnterpriseDecisionResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = EnterpriseDecision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(), Select::make('actor_id')->options(fn () => \App\Models\User::query()->whereHas('memberships', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()))->pluck('name', 'id'))->searchable()->preload()->required(), Textarea::make('title')->required(), Textarea::make('summary')->required(), Textarea::make('rationale'), DateTimePicker::make('decided_at')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')->searchable(), TextColumn::make('enterprise.name')->searchable(), TextColumn::make('actor_name')->searchable(), TextColumn::make('decided_at')->dateTime()])->recordActions([\Filament\Actions\EditAction::make()]);
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
        return ['index' => ListEnterpriseDecisions::route('/'), 'create' => CreateEnterpriseDecision::route('/create'), 'edit' => EditEnterpriseDecision::route('/{record}/edit')];
    }
}
