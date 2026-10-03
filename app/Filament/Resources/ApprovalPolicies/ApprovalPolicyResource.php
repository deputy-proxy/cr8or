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

    protected static ?string $navigationLabel = 'ApprovalPolicies';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalPolicies::route('/'),
        ];
    }
}
