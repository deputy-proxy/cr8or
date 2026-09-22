<?php

namespace App\Filament\Resources\ApprovalRequests;

use App\Filament\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
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
    use ScopesPhaseOneRecords;

    protected static ?string $model = ApprovalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

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
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListApprovalRequests::route('/')];
    }
}