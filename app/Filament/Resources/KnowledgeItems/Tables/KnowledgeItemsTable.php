<?php

namespace App\Filament\Resources\KnowledgeItems\Tables;

use App\Filament\Resources\KnowledgeItems\KnowledgeItemResource;
use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class KnowledgeItemsTable
{
    public static function configure(Table $table): Table
    {
        $authorizedEnterpriseIds = fn () => Enterprise::query()
            ->select('id')
            ->whereIn('organization_id', KnowledgeItemResource::authorizedOrganizationIds());

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
                SelectFilter::make('knowledge_document_id')
                    ->label('Document')
                    ->options(fn (): array => KnowledgeDocument::query()
                        ->whereIn('enterprise_id', $authorizedEnterpriseIds())
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('type')
                    ->options(fn (): array => KnowledgeItem::query()
                        ->whereIn('enterprise_id', $authorizedEnterpriseIds())
                        ->whereNotNull('type')
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->all()),
                SelectFilter::make('knowledge_context_id')
                    ->label('Context')
                    ->options(fn (): array => KnowledgeContext::query()
                        ->whereIn('enterprise_id', $authorizedEnterpriseIds())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
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
            ])
            ->toolbarActions([
                BulkAction::make('delete')
                    ->label('Delete selected')
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(function (Collection $records): void {
                        DB::transaction(function () use ($records): void {
                            // Authorize the entire selection before deleting anything.
                            $records->each(fn (KnowledgeItem $record) => Gate::authorize('delete', $record));

                            $records->each(fn (KnowledgeItem $record) => $record->delete());
                        });
                    }),
            ]);
    }
}

