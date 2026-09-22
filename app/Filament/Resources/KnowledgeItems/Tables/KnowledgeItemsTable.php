<?php

namespace App\Filament\Resources\KnowledgeItems\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KnowledgeItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('type')->searchable()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
