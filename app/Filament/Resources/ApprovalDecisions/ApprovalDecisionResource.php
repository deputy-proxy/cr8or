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

    protected static ?int $navigationSort = 150;

    protected static ?string $navigationLabel = 'Approval Decisions';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('approvalRequest.capability')->label('Capability')->searchable()->sortable(),
            TextColumn::make('stage')->label('Stage')->searchable()->sortable(),
            TextColumn::make('decision')->label('Decision')->searchable()->sortable(),
            TextColumn::make('actor_name')->label('Actor Name')->searchable()->sortable(),
            TextColumn::make('decided_at')->label('Decided At')->searchable()->sortable(),
            TextColumn::make('reason')->label('Reason')->searchable()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalDecisions::route('/'),
        ];
    }
}
