<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Assignments\AssignmentResource;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Decisions\DecisionResource;
use App\Filament\Resources\Dependencies\DependencyResource;
use App\Filament\Resources\EnterpriseDecisions\EnterpriseDecisionResource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Milestones\MilestoneResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Models\Assignment;
use App\Models\Decision;
use App\Models\Dependency;
use App\Models\Enterprise;
use App\Models\EnterpriseDecision;
use App\Models\Initiative;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkItem;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class WorkOverview extends Page
{
    use ScopesAuthorizedRecords;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Work Overview';

    protected static ?string $title = 'Work Overview';

    protected static string|UnitEnum|null $navigationGroup = 'Work Management';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.work-overview';

    /** @var array<int, array{label: string, count: int, url: string}> */
    public array $overview = [];

    /** @var array{nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>} */
    public array $sankeyData = ['nodes' => [], 'links' => []];

    public function mount(): void
    {
        $user = static::currentUser();

        if (! ($user instanceof User)) {
            abort(403);
        }

        $organizationIds = static::authorizedOrganizationIds();
        $enterpriseIds = Enterprise::query()
            ->whereIn('organization_id', $organizationIds)
            ->select('id');

        $this->overview = [
            ['label' => 'Initiatives', 'count' => Initiative::query()
                ->whereHas('plan.strategy.objective', fn (Builder $query) => $query->whereIn('enterprise_id', $enterpriseIds))
                ->count(), 'url' => InitiativeResource::getUrl()],
            ['label' => 'Projects', 'count' => $this->countForAuthorizedEnterprises(Project::class), 'url' => ProjectResource::getUrl()],
            ['label' => 'Milestones', 'count' => $this->countForAuthorizedEnterprises(Milestone::class), 'url' => MilestoneResource::getUrl()],
            ['label' => 'Tasks', 'count' => $this->countForAuthorizedEnterprises(Task::class), 'url' => TaskResource::getUrl()],
            ['label' => 'Work Items', 'count' => $this->countForAuthorizedEnterprises(WorkItem::class), 'url' => WorkItemResource::getUrl()],
            ['label' => 'Dependencies', 'count' => $this->countForAuthorizedEnterprises(Dependency::class), 'url' => DependencyResource::getUrl()],
            ['label' => 'Assignments', 'count' => $this->countForAuthorizedEnterprises(Assignment::class), 'url' => AssignmentResource::getUrl()],
            ['label' => 'Decisions', 'count' => $this->countForAuthorizedEnterprises(Decision::class), 'url' => DecisionResource::getUrl()],
            ['label' => 'Enterprise Decisions', 'count' => $this->countForAuthorizedEnterprises(EnterpriseDecision::class), 'url' => EnterpriseDecisionResource::getUrl()],
        ];

        $this->sankeyData = $this->buildSankeyData();
    }

    public static function canAccess(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    /**
     * @param  class-string<Assignment|Decision|Dependency|EnterpriseDecision|Milestone|Project|Task|WorkItem>  $model
     */
    private function countForAuthorizedEnterprises(string $model): int
    {
        return $model::query()
            ->whereHas('enterprise', fn (Builder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()))
            ->count();
    }

    /**
     * Build the chart exclusively from records inside the user's authorized organizations.
     * Tasks are intentionally attached to Projects because the current domain model does not
     * define a Task-to-WorkItem relationship. Assignments, dependencies and decisions are
     * linked to WorkItems only where their persisted polymorphic/foreign-key relationship says so.
     *
     * @return array{nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}
     */
    private function buildSankeyData(): array
    {
        $enterprises = Enterprise::query()
            ->whereIn('organization_id', static::authorizedOrganizationIds())
            ->orderBy('name')
            ->get(['id', 'name']);

        $ids = $enterprises->modelKeys();
        $nodes = [];
        $links = [];
        $known = [];

        $addNode = function (string $id, string $label, string $type, string $url, ?string $parent = null) use (&$nodes, &$links, &$known): void {
            if (isset($known[$id])) {
                return;
            }

            $known[$id] = true;
            $nodes[] = ['name' => $id, 'label' => $label, 'type' => $type, 'url' => $url];
            if ($parent !== null && isset($known[$parent])) {
                $links[] = ['source' => $parent, 'target' => $id, 'value' => 1];
            }
        };

        foreach ($enterprises as $enterprise) {
            $addNode('enterprise:'.$enterprise->id, 'Enterprise: '.$enterprise->name, 'organization', '#');
        }

        $initiatives = Initiative::query()
            ->whereHas('plan.strategy.objective', fn (Builder $query) => $query->whereIn('enterprise_id', $ids))
            ->with('plan.strategy.objective')
            ->orderBy('name')
            ->get();

        $initiativeEnterprise = [];
        foreach ($initiatives as $initiative) {
            $enterpriseId = $initiative->plan?->strategy?->objective?->enterprise_id;
            if (! $enterpriseId || ! in_array((int) $enterpriseId, $ids, true)) {
                continue;
            }
            $id = 'initiative:'.$initiative->id;
            $initiativeEnterprise[$initiative->id] = (int) $enterpriseId;
            $addNode($id, 'Initiative: '.$initiative->name, 'planning', InitiativeResource::getUrl(), 'enterprise:'.$enterpriseId);
        }

        $projects = Project::query()->whereIn('enterprise_id', $ids)->orderBy('name')->get();
        $projectIds = $projects->modelKeys();
        foreach ($projects as $project) {
            $parent = $project->initiative_id && isset($initiativeEnterprise[$project->initiative_id])
                ? 'initiative:'.$project->initiative_id
                : 'enterprise:'.$project->enterprise_id;
            $addNode('project:'.$project->id, 'Project: '.$project->name, 'planning', ProjectResource::getUrl(), $parent);
        }

        $workItems = WorkItem::query()->whereIn('enterprise_id', $ids)->whereIn('project_id', $projectIds)->orderBy('name')->get();
        $workItemIds = $workItems->modelKeys();
        foreach ($workItems as $workItem) {
            $addNode('work-item:'.$workItem->id, 'Work Item: '.$workItem->name, 'work', WorkItemResource::getUrl(), 'project:'.$workItem->project_id);
        }

        $milestones = Milestone::query()->whereIn('enterprise_id', $ids)->whereIn('project_id', $projectIds)->orderBy('name')->get();
        foreach ($milestones as $milestone) {
            $addNode('milestone:'.$milestone->id, 'Milestone: '.$milestone->name, 'planning', MilestoneResource::getUrl(), 'project:'.$milestone->project_id);
        }

        // Tasks are project-scoped in the current schema, not children of WorkItems.
        $tasks = Task::query()->whereIn('enterprise_id', $ids)->whereIn('project_id', $projectIds)->orderBy('name')->get();
        $taskIds = $tasks->modelKeys();
        foreach ($tasks as $task) {
            $addNode('task:'.$task->id, 'Task: '.$task->name, 'supporting', TaskResource::getUrl(), 'project:'.$task->project_id);
        }
        foreach ($tasks as $task) {
            if (! $task->parent_task_id || ! in_array($task->parent_task_id, $taskIds)) {
                continue;
            }

            $taskNodeId = 'task:'.$task->id;
            $links = array_values(array_filter($links, fn (array $link): bool => $link['target'] !== $taskNodeId));
            $links[] = ['source' => 'task:'.$task->parent_task_id, 'target' => $taskNodeId, 'value' => 1];
        }

        $workItemClass = (new WorkItem)->getMorphClass();
        $assignments = Assignment::query()
            ->whereIn('enterprise_id', $ids)
            ->where('assignable_type', $workItemClass)
            ->whereIn('assignable_id', $workItemIds)
            ->with('user')
            ->orderBy('id')
            ->get();
        foreach ($assignments as $assignment) {
            $person = $assignment->user_id ? $assignment->user->name : ($assignment->agent_assignment_id ? 'Agent assignment #'.$assignment->agent_assignment_id : 'Unassigned');
            $addNode('assignment:'.$assignment->id, 'Assignment: '.$person, 'supporting', AssignmentResource::getUrl(), 'work-item:'.$assignment->assignable_id);
        }

        $decisions = Decision::query()
            ->whereIn('enterprise_id', $ids)
            ->whereIn('work_item_id', $workItemIds)
            ->orderBy('id')
            ->get();
        foreach ($decisions as $decision) {
            $addNode('decision:'.$decision->id, 'Decision: '.$decision->title, 'supporting', DecisionResource::getUrl(), 'work-item:'.$decision->work_item_id);
        }

        $dependencies = Dependency::query()
            ->whereIn('enterprise_id', $ids)
            ->where(function (Builder $query) use ($workItemClass, $workItemIds): void {
                $query->where(function (Builder $q) use ($workItemClass, $workItemIds): void {
                    $q->where('predecessor_type', $workItemClass)->whereIn('predecessor_id', $workItemIds);
                })->orWhere(function (Builder $q) use ($workItemClass, $workItemIds): void {
                    $q->where('successor_type', $workItemClass)->whereIn('successor_id', $workItemIds);
                });
            })
            ->orderBy('id')
            ->get();
        foreach ($dependencies as $dependency) {
            $parentWorkItemId = $dependency->predecessor_type === $workItemClass && in_array($dependency->predecessor_id, $workItemIds)
                ? (int) $dependency->predecessor_id
                : (int) $dependency->successor_id;
            if (! isset($known['work-item:'.$parentWorkItemId])) {
                continue;
            }
            $addNode('dependency:'.$dependency->id, 'Dependency: '.$dependency->type, 'supporting', DependencyResource::getUrl(), 'work-item:'.$parentWorkItemId);
        }

        $enterpriseDecisions = EnterpriseDecision::query()->whereIn('enterprise_id', $ids)->orderBy('id')->get();
        foreach ($enterpriseDecisions as $decision) {
            $addNode('enterprise-decision:'.$decision->id, 'Enterprise Decision: '.$decision->title, 'governance', EnterpriseDecisionResource::getUrl(), 'enterprise:'.$decision->enterprise_id);
        }

        return ['nodes' => $nodes, 'links' => $links];
    }
}