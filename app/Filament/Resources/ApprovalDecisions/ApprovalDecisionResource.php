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

    protected static ?string $modelLabel = 'Approval Decision';

    protected static ?string $pluralModelLabel = 'Approval Decisions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Agent Operations & Governance';

    protected static ?string $navigationLabel = 'Approval Decisions';

    protected static ?int $navigationSort = 60;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('approval_request_id')->label('Approval Request')->searchable()->sortable(),
            TextColumn::make('stage')->badge()->searchable()->sortable(),
            TextColumn::make('decision')->badge()->searchable()->sortable(),
            TextColumn::make('actor_name')->label('Actor')->searchable()->sortable(),
            TextColumn::make('decided_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalDecisions::route('/'),
        ];
    }
}
