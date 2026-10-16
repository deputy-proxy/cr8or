<?php

namespace App\Filament\Resources\ApprovalPolicies;

use App\Filament\Resources\ApprovalPolicies\Pages\CreateApprovalPolicy;
use App\Filament\Resources\ApprovalPolicies\Pages\EditApprovalPolicy;
use App\Filament\Resources\ApprovalPolicies\Pages\ListApprovalPolicies;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Models\ApprovalPolicy;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApprovalPolicyResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = ApprovalPolicy::class;

    protected static ?string $modelLabel = 'Approval Policy';

    protected static ?string $pluralModelLabel = 'Approval Policies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agent Governance & Operations';

    protected static ?string $navigationLabel = 'Approval Policies';

    protected static ?int $navigationSort = 90;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return static::scopeAuthorizedOwnership(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')->relationship('organization', 'name')->searchable()->preload()->required(),
            Select::make('enterprise_id')->relationship('enterprise', 'name')->searchable()->preload(),
            TextInput::make('policy_key')->required()->maxLength(255),
            TextInput::make('capability')->required()->maxLength(255),
            Repeater::make('stages')
                ->schema([
                    TextInput::make('required')->numeric()->minValue(1)->required(),
                    TagsInput::make('roles')->required(),
                ])
                ->defaultItems(1)
                ->minItems(1)
                ->required(),
            TextInput::make('expires_in_minutes')->numeric()->minValue(1)->required(),
            Toggle::make('allow_self_approval')->default(false),
            TagsInput::make('escalation_roles'),
            Toggle::make('enabled')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('policy_key')->searchable()->sortable(),
            TextColumn::make('capability')->searchable()->sortable(),
            TextColumn::make('expires_in_minutes')->numeric()->sortable(),
            TextColumn::make('allow_self_approval')->badge()->sortable(),
            TextColumn::make('enabled')->badge()->sortable(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalPolicies::route('/'),
            'create' => CreateApprovalPolicy::route('/create'),
            'edit' => EditApprovalPolicy::route('/{record}/edit'),
        ];
    }
}
