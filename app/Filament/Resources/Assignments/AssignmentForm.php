<?php

namespace App\Filament\Resources\Assignments;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\AgentAssignment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItem;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AssignmentForm
{
    use ScopesPhaseOneRecords;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->relationship('enterprise', 'name')
                ->searchable()
                ->preload()
                ->required(),
            Select::make('assignable_type')
                ->label('Work record type')
                ->options([
                    Project::class => 'Project',
                    Task::class => 'Task',
                    WorkItem::class => 'Work item',
                ])
                ->live()
                ->required(),
            Select::make('assignable_id')
                ->label('Work record')
                ->options(fn (Get $get): array => self::workRecordOptions($get('assignable_type')))
                ->searchable()
                ->preload()
                ->disabled(fn (Get $get): bool => blank($get('assignable_type')))
                ->required(),
            Select::make('user_id')
                ->label('User assignee')
                ->options(fn (): array => User::query()
                    ->whereHas('memberships', fn (Builder $query) => $query->whereIn('organization_id', self::manageableOrganizationIds()))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload(),
            Select::make('agent_assignment_id')
                ->label('Agent assignee')
                ->options(fn (): array => AgentAssignment::query()
                    ->with('agentDescriptor')
                    ->whereIn('organization_id', self::manageableOrganizationIds())
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
    private static function workRecordOptions(?string $type): array
    {
        $model = match ($type) {
            Project::class => Project::class,
            Task::class => Task::class,
            WorkItem::class => WorkItem::class,
            default => null,
        };

        if ($model === null) {
            return [];
        }

        return $model::query()
            ->whereIn('enterprise_id', self::manageableEnterpriseIds())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
