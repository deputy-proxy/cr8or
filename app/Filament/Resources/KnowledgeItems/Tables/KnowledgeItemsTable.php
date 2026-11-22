<?php

namespace App\Filament\Resources\KnowledgeItems\Tables;

use App\Filament\Resources\KnowledgeItems\KnowledgeItemResource;
use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class KnowledgeItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
                TextColumn::make('source.name')->label('Source')->searchable()->sortable(),
                TextColumn::make('document.title')->label('Document')->searchable()->sortable(),
                TextColumn::make('context.name')->label('Context')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('type')->searchable()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('enterprise_id')
                    ->label('Enterprise')
                    ->options(fn (): array => Enterprise::query()
                        ->whereIn('organization_id', KnowledgeItemResource::authorizedOrganizationIds())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->groups([
                Group::make('enterprise_id')
                    ->label('Enterprise')
                    ->getKeyFromRecordUsing(fn (KnowledgeItem $record): int => $record->enterprise_id)
                    ->getTitleFromRecordUsing(fn (KnowledgeItem $record): string => $record->enterprise->name)
                    ->titlePrefixedWithLabel(false)
                    ->collapsible(),
            ])
            ->defaultGroup('enterprise_id')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
