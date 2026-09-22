<?php

namespace App\Filament\Resources\KnowledgeDocuments\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KnowledgeDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')->searchable()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
