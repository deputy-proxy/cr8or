<?php

namespace App\Filament\Resources\KnowledgeVersions\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KnowledgeVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('version')->sortable(),
            TextColumn::make('recorded_at')->dateTime()->sortable(),
        ]);
    }
}
