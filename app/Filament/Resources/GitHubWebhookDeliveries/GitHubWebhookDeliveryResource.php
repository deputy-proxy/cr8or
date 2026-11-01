<?php

namespace App\Filament\Resources\GitHubWebhookDeliveries;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\GitHubWebhookDeliveries\Pages\ListGitHubWebhookDeliveries;
use App\Models\GitHubWebhookDelivery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class GitHubWebhookDeliveryResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = GitHubWebhookDelivery::class;

    protected static ?string $modelLabel = 'GitHub Webhook Delivery';

    protected static ?string $pluralModelLabel = 'GitHub Webhook Deliveries';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static string|\UnitEnum|null $navigationGroup = 'Workflows & Integrations';

    protected static ?string $navigationLabel = 'GitHub Webhook Deliveries';

    protected static ?int $navigationSort = 57;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('delivery_id')->label('Delivery ID')->searchable()->copyable()->limit(36),
            TextColumn::make('organization.name')->label('Organization')->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->placeholder('Organization scoped')->sortable(),
            TextColumn::make('event_type')->label('Event')->badge()->sortable(),
            TextColumn::make('action')->placeholder('—')->sortable(),
            TextColumn::make('github_repository_id')->label('Repository ID')->placeholder('—'),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('received_at')->dateTime()->sortable(),
            TextColumn::make('processed_at')->dateTime()->placeholder('Pending')->sortable(),
            TextColumn::make('failure_reason')->limit(80)->toggleable(),
        ])->defaultSort('received_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('organization_id', static::authorizedOrganizationIds());
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
        return ['index' => ListGitHubWebhookDeliveries::route('/')];
    }
}
