<?php

namespace App\Filament\Resources\KnowledgeReferences\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KnowledgeReferencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->searchable()->sortable(),
                TextColumn::make('type')->searchable()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
