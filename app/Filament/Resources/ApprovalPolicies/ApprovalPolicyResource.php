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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?int $navigationSort = 130;

    protected static ?string $navigationLabel = 'Approval Policies';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('policy_key')->label('Policy Key')->searchable()->sortable(),
            TextColumn::make('capability')->label('Capability')->searchable()->sortable(),
            TextColumn::make('enabled')->label('Enabled')->searchable()->sortable(),
            TextColumn::make('expires_in_minutes')->label('Expires In Minutes')->searchable()->sortable(),
            TextColumn::make('allow_self_approval')->label('Allow Self Approval')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalPolicies::route('/'),
        ];
    }
}
