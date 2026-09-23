<?php

namespace App\Filament\Resources\Channels;

use App\Filament\Resources\Channels\Pages\CreateChannel;
use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\Channel;
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

class ChannelResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Channel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Select::make('enterprise_id')->relationship('enterprise', 'name', fn (Builder $q) => $q->whereIn('id', static::manageableEnterpriseIds()))->searchable()->preload()->required(), TextInput::make('name')->required()->maxLength(255), TextInput::make('type')->required()->maxLength(100), Select::make('status')->options(['active' => 'Active', 'archived' => 'Archived'])->default('active')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('type')->badge(), TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(), TextColumn::make('status')->badge()->sortable()])->recordActions([EditAction::make(), DeleteAction::make()]);
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
        return ['index' => ListChannels::route('/'), 'create' => CreateChannel::route('/create'), 'edit' => EditChannel::route('/{record}/edit')];
    }
}