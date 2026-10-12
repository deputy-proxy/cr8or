<?php

namespace App\Filament\Resources\Assignments;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AssignmentForm
{
    use ScopesAuthorizedRecords;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->options(fn (): array => Enterprise::query()
                    ->whereIn('id', self::manageableEnterpriseIds())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->live(),
            Select::make('assignable_type')
                ->label('Work record type')
                ->options([Project::class => 'Project', Task::class => 'Task'])
                ->live()
                ->required(),
            Select::make('assignable_id')
                ->label('Work record')
                ->options(fn (Get $get): array => self::workRecordOptions($get('assignable_type'), $get('enterprise_id')))
                ->searchable()
                ->preload()
                ->disabled(fn (Get $get): bool => blank($get('assignable_type')) || blank($get('enterprise_id')))
                ->required(),
            Select::make('user_id')
                ->label('User assignee')
                ->options(fn (Get $get): array => User::query()
                    ->whereHas('memberships', fn (Builder $query) => $query->whereIn('organization_id', self::manageableOrganizationIds())
                        ->when($get('enterprise_id'), fn (Builder $membershipQuery, mixed $enterpriseId) => $membershipQuery->whereIn(
                            'organization_id',
                            Enterprise::query()->whereKey($enterpriseId)->select('organization_id'),
                        )))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload(),
            Select::make('agent_assignment_id')
                ->label('Agent assignee')
                ->options(fn (Get $get): array => AgentAssignment::query()
                    ->with('agentDescriptor')
                    ->whereIn('organization_id', self::manageableOrganizationIds())
                    ->when($get('enterprise_id'), fn (Builder $query, mixed $enterpriseId) => $query->whereIn(
                        'organization_id',
                        Enterprise::query()->whereKey($enterpriseId)->select('organization_id'),
                    ))
                    ->get()
                    ->mapWithKeys(fn (AgentAssignment $assignment): array => [
                        $assignment->id => $assignment->agentDescriptor->slug,
                    ])
                    ->all())
                ->searchable()
                ->preload(),
        ]);
    }

    /** @return array<int, string> */
    private static function workRecordOptions(?string $type, mixed $enterpriseId): array
    {
        $model = match ($type) {
            Project::class => Project::class,
            Task::class => Task::class,
            default => null,
        };

        if ($model === null || blank($enterpriseId)) {
            return [];
        }

        return $model::query()
            ->where('enterprise_id', $enterpriseId)
            ->whereIn('enterprise_id', self::manageableEnterpriseIds())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
