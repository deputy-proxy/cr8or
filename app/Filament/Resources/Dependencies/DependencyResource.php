<?php

namespace App\Filament\Resources\Dependencies;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Dependencies\Pages\CreateDependency;
use App\Filament\Resources\Dependencies\Pages\EditDependency;
use App\Filament\Resources\Dependencies\Pages\ListDependencies;
use App\Models\Dependency;
use App\Models\Enterprise;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DependencyResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = Dependency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    public static function form(Schema $schema): Schema
    {
        return DependencyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('predecessor.name')->label('Predecessor'),
            TextColumn::make('successor.name')->label('Successor'),
            TextColumn::make('type')->badge()->sortable(),
            TextColumn::make('project.name')->label('Project')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['predecessor', 'successor', 'project', 'enterprise'])
            ->whereIn('enterprise_id', static::authorizedOrganizationEnterpriseIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function canCreate(): bool
    {
        return auth()->check() && static::canManageAnyEnterprise();
    }

    /** @return Builder<Enterprise> */
    protected static function authorizedOrganizationEnterpriseIds(): Builder
    {
        return Enterprise::query()
            ->select('id')
            ->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDependencies::route('/'),
            'create' => CreateDependency::route('/create'),
            'edit' => EditDependency::route('/{record}/edit'),
        ];
    }
}
