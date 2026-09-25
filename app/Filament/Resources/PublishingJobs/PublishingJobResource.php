<?php

namespace App\Filament\Resources\PublishingJobs;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Filament\Resources\PublishingJobs\Pages\ListPublishingJobs;
use App\Models\PublishingJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PublishingJobResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = PublishingJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?string $navigationLabel = 'Publishing Jobs';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enterprise.name')->searchable()->sortable(),
            TextColumn::make('publication.id')->searchable()->sortable(),
            TextColumn::make('idempotency_key')->searchable()->sortable(),
            TextColumn::make('attempts')->searchable()->sortable(),
            TextColumn::make('status')->badge()->searchable()->sortable(),
            TextColumn::make('failure_code')->searchable()->sortable(),
            TextColumn::make('failure_reason')->searchable()->sortable(),
            TextColumn::make('started_at')->searchable()->sortable(),
        ])->recordActions([

        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('enterprise_id', static::authorizedEnterpriseIds());
    }

    /** @return Builder<\App\Models\Enterprise> */
    protected static function authorizedEnterpriseIds(): Builder
    {
        return \App\Models\Enterprise::query()->select('enterprises.id')->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListPublishingJobs::route('/')];
    }
}