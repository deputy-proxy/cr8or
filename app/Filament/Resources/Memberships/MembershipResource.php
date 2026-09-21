<?php
namespace App\Filament\Resources\Memberships;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\Memberships\Pages\CreateMembership;
use App\Filament\Resources\Memberships\Pages\EditMembership;
use App\Filament\Resources\Memberships\Pages\ListMemberships;
use App\Models\Membership;
use BackedEnum;
use Filament\Forms\Components\{Select,TextInput,Textarea,DateTimePicker};
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
class MembershipResource extends Resource {
 use ScopesPhaseOneRecords;
 protected static ?string $model=Membership::class;
 protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedUsers;
 public static function form(Schema $schema): Schema { return $schema->components([Select::make('user_id')->relationship('user','name')->searchable()->preload()->required(), Select::make('organization_id')->relationship('organization','name',fn(Builder $q)=>$q->whereIn('id',static::manageableOrganizationIds()))->searchable()->preload()->required(), Select::make('role')->options(\App\Enums\MembershipRole::class)->required(),]); }
 public static function table(Table $table): Table { return $table->columns([TextColumn::make('user.name')->searchable()->sortable(),TextColumn::make('organization.name')->searchable()->sortable(),TextColumn::make('role')->badge(),])->recordActions([\Filament\Actions\EditAction::make(),]); }
 public static function getEloquentQuery(): Builder { return parent::getEloquentQuery()->whereIn('organization_id',static::authorizedOrganizationIds()); }
 public static function canViewAny(): bool { return auth()->check()&&static::authorizedOrganizationIds()->exists(); }
 public static function canCreate(): bool { return auth()->check()&&static::canManageAnyOrganization(); }
 public static function getPages(): array { return ['index'=>ListMemberships::route('/'),'create'=>CreateMembership::route('/create'),'edit'=>EditMembership::route('/{record}/edit')]; }
}