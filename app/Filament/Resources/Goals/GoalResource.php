<?php
namespace App\Filament\Resources\Goals;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Models\Goal;
use BackedEnum;
use Filament\Forms\Components\{Select,TextInput,Textarea,DateTimePicker};
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
class GoalResource extends Resource {
 use ScopesPhaseOneRecords;
 protected static ?string $model=Goal::class;
 protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedFlag;
 public static function form(Schema $schema): Schema { return $schema->components([Select::make('enterprise_id')->relationship('enterprise','name',fn(Builder $q)=>$q->whereIn('id',static::manageableEnterpriseIds()))->searchable()->preload()->required(), TextInput::make('name')->required()->maxLength(255), Textarea::make('description')->rows(4), Select::make('status')->options(['active'=>'Active','archived'=>'Archived'])->default('active')->required(),]); }
 public static function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()->sortable(),TextColumn::make('enterprise.name')->searchable()->sortable(),TextColumn::make('status')->badge(),])->recordActions([\Filament\Actions\EditAction::make(),\Filament\Actions\DeleteAction::make(),]); }
 public static function getEloquentQuery(): Builder { return parent::getEloquentQuery()->whereHas('enterprise',fn(Builder $q)=>$q->whereIn('organization_id',static::authorizedOrganizationIds())); }
 public static function canViewAny(): bool { return auth()->check()&&static::authorizedOrganizationIds()->exists(); }
 public static function canCreate(): bool { return auth()->check()&&static::canManageAnyEnterprise(); }
 public static function getPages(): array { return ['index'=>ListGoals::route('/'),'create'=>CreateGoal::route('/create'),'edit'=>EditGoal::route('/{record}/edit')]; }
}