<?php

namespace App\Filament\Resources\KnowledgeContexts\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KnowledgeContextsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->searchable()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
