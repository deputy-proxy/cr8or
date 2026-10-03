<?php

namespace App\Filament\Resources\ApprovalDecisions;

use App\Filament\Resources\ApprovalDecisions\Pages\ListApprovalDecisions;
use App\Models\ApprovalDecision;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApprovalDecisionResource extends Resource
{
    protected static ?string $model = ApprovalDecision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?string $navigationLabel = 'ApprovalDecisions';

    protected static ?int $navigationSort = 150;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalDecisions::route('/'),
        ];
    }
}
