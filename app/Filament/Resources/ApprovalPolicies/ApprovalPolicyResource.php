<?php

namespace App\Filament\Resources\ApprovalPolicies;

use App\Filament\Resources\ApprovalPolicies\Pages\ListApprovalPolicies;
use App\Models\ApprovalPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApprovalPolicyResource extends Resource
{
    protected static ?string $model = ApprovalPolicy::class;

    protected static ?string $modelLabel = 'Approval Policy';

    protected static ?string $pluralModelLabel = 'Approval Policies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?string $navigationLabel = 'Approval Policies';

    protected static ?int $navigationSort = 130;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('policy_key')->searchable()->sortable(),
            TextColumn::make('capability')->searchable()->sortable(),
            TextColumn::make('expires_in_minutes')->numeric()->sortable(),
            TextColumn::make('allow_self_approval')->badge()->sortable(),
            TextColumn::make('enabled')->badge()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalPolicies::route('/'),
        ];
    }
}
