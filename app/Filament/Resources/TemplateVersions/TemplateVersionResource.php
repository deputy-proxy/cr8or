<?php

namespace App\Filament\Resources\TemplateVersions;

use App\Filament\Resources\Templates\TemplateResource;
use App\Filament\Resources\TemplateVersions\Pages\ListTemplateVersions;
use App\Models\TemplateVersion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class TemplateVersionResource extends Resource
{
    protected static ?string $model = TemplateVersion::class;

    protected static ?string $modelLabel = 'Template Version';

    protected static ?string $pluralModelLabel = 'Template Versions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Media Production & Publishing';

    protected static ?string $navigationLabel = 'Template Versions';

    protected static ?int $navigationSort = 16;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('template.name')->searchable()->sortable(),
            TextColumn::make('template.key')->searchable()->sortable(),
            TextColumn::make('version')->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('schema_version')->sortable(),
            TextColumn::make('definition_sha256')->label('SHA-256')->copyable()->limit(16),
            TextColumn::make('source_revision')->label('Source commit')->copyable()->limit(12),
            TextColumn::make('definition_path')->searchable(),
            TextColumn::make('definition_artifact_path')->label('R2 artifact')->copyable()->limit(32),
            TextColumn::make('published_at')->dateTime()->sortable(),
            TextColumn::make('retired_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options([
                TemplateVersion::STATUS_DRAFT => 'Draft',
                TemplateVersion::STATUS_PUBLISHED => 'Published',
                TemplateVersion::STATUS_RETIRED => 'Retired',
            ]),
            SelectFilter::make('template_id')->label('Template')->options(fn (): array => TemplateResource::getEloquentQuery()->orderBy('name')->pluck('name', 'id')->all()),
        ])->defaultSort('template_id')->recordActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('template_id', TemplateResource::getEloquentQuery()->select('templates.id'))
            ->with('template');
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return auth()->check() && Gate::allows('create', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListTemplateVersions::route('/')];
    }
}
