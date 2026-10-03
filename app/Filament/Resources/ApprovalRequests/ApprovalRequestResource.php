<?php

namespace App\Filament\Resources\ApprovalRequests;

use App\Filament\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Models\ApprovalRequest;
use App\Services\ApprovalRequestService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ApprovalRequestResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = ApprovalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?string $navigationLabel = 'Approvals';

    protected static ?int $navigationSort = 140;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('capability')->searchable()->sortable(),
            TextColumn::make('agent_slug')->label('Agent')->searchable()->sortable(),
            TextColumn::make('organization_name')->label('Organization')->searchable()->sortable(),
            TextColumn::make('enterprise_name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('actor_name')->label('Actor')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('requested_at')->dateTime()->sortable(),
            TextColumn::make('expires_at')->dateTime()->sortable(),
        ])->recordActions([
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->visible(fn (ApprovalRequest $record): bool => $record->status === ApprovalRequest::STATUS_PENDING && Gate::allows('approve', $record))
                ->action(fn (ApprovalRequest $record): ApprovalRequest => app(ApprovalRequestService::class)->approve($record, auth()->user())),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->visible(fn (ApprovalRequest $record): bool => $record->status === ApprovalRequest::STATUS_PENDING && Gate::allows('reject', $record))
                ->action(fn (ApprovalRequest $record): ApprovalRequest => app(ApprovalRequestService::class)->reject($record, auth()->user())),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('create', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListApprovalRequests::route('/')];
    }
}
