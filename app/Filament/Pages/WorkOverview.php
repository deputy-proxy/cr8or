<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Assignments\AssignmentResource;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Decisions\DecisionResource;
use App\Filament\Resources\Dependencies\DependencyResource;
use App\Filament\Resources\EnterpriseDecisions\EnterpriseDecisionResource;
use App\Filament\Resources\Enterprises\EnterpriseResource;
use App\Filament\Resources\Initiatives\InitiativeResource;
use App\Filament\Resources\Milestones\MilestoneResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Tasks\TaskResource;
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
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class WorkOverview extends Page
{
    use ScopesAuthorizedRecords;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Work Overview';

    protected static ?string $title = 'Work Overview';

    protected static string|UnitEnum|null $navigationGroup = 'Strategy & Execution Planning';

    protected static ?int $navigationSort = 125;

    protected string $view = 'filament.pages.work-overview';

    /** @var array<int, array{label: string, count: int, url: string}> */
    public array $overview = [];

    /** @var array<int, array<string, mixed>> */
    public array $treeData = [];

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
            ['label' => 'Dependencies', 'count' => $this->countForAuthorizedEnterprises(Dependency::class), 'url' => DependencyResource::getUrl()],
            ['label' => 'Assignments', 'count' => $this->countForAuthorizedEnterprises(Assignment::class), 'url' => AssignmentResource::getUrl()],
            ['label' => 'Decisions', 'count' => $this->countForAuthorizedEnterprises(Decision::class), 'url' => DecisionResource::getUrl()],
            ['label' => 'Enterprise Decisions', 'count' => $this->countForAuthorizedEnterprises(EnterpriseDecision::class), 'url' => EnterpriseDecisionResource::getUrl()],
        ];

        $this->treeData = $this->buildTreeData();
    }

    public static function canAccess(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    /**
     * @param  class-string<Assignment|Decision|Dependency|EnterpriseDecision|Milestone|Project|Task>  $model
     */
    private function countForAuthorizedEnterprises(string $model): int
    {
        return $model::query()
            ->whereHas('enterprise', fn (Builder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()))
            ->count();
    }

    /**
     * @return array{status: ?string, dueAt: ?string, overdue: bool}
     */
    private function statusMetadata(?string $status, mixed $dueAt = null): array
    {
        $normalizedStatus = strtolower((string) $status);
        $isTerminal = in_array($normalizedStatus, ['completed', 'done', 'cancelled', 'canceled'], true);
        $dueDate = $dueAt ? Carbon::parse($dueAt) : null;

        return [
            'status' => $status,
            'dueAt' => $dueDate?->toIso8601String(),
            'overdue' => $dueDate !== null && $dueDate->isPast() && ! $isTerminal,
        ];
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function recordEditUrl(string $resourceClass, Model $record): string
    {
        if (! $resourceClass::canEdit($record)) {
            return '#';
        }

        return $resourceClass::getUrl('edit', ['record' => $record]);
    }

    /**
     * Build a nested tree from records inside the user's authorized organizations.
     * Tasks retain their persisted parent-child hierarchy and are not implicitly linked to Workflow records.
     * Assignments, dependencies, and decisions follow their persisted relationships to Tasks.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTreeData(): array
    {
        $enterprises = Enterprise::query()
            ->whereIn('organization_id', static::authorizedOrganizationIds())
            ->orderBy('name')
            ->get(['id', 'name', 'organization_id']);

        $enterpriseIds = $enterprises->modelKeys();
        $nodes = [];
        $links = [];
        $known = [];

        $addNode = function (string $id, string $label, string $type, string $url, ?string $parent = null, array $metadata = []) use (&$nodes, &$links, &$known): void {
            if (isset($known[$id])) {
                return;
            }
            $known[$id] = true;
            $nodes[] = array_merge(['name' => $id, 'displayLabel' => $label, 'type' => $type, 'url' => $url], $metadata);
            if ($parent !== null && isset($known[$parent])) {
                $links[] = ['source' => $parent, 'target' => $id, 'value' => 1];
            }
        };

        foreach ($enterprises as $enterprise) {
            $enterpriseNode = 'enterprise:'.$enterprise->id;
            $addNode($enterpriseNode, 'Enterprise: '.$enterprise->name, 'organization', $this->recordEditUrl(EnterpriseResource::class, $enterprise));
            $addNode('group:tasks:enterprise:'.$enterprise->id, 'Tasks without a Project', 'group', '#', $enterpriseNode, [
                'groupType' => 'tasks',
                'enterpriseId' => $enterprise->id,
            ]);
        }

        $initiatives = Initiative::query()
            ->whereHas('plan.strategy.objective', fn (Builder $query) => $query->whereIn('enterprise_id', $enterpriseIds))
            ->with('plan.strategy.objective')
            ->orderBy('name')
            ->get();

        foreach ($initiatives as $initiative) {
            $enterpriseId = $initiative->plan?->strategy?->objective?->enterprise_id;
            if (! $enterpriseId || ! in_array((int) $enterpriseId, $enterpriseIds, true)) {
                continue;
            }
            $addNode('initiative:'.$initiative->id, 'Initiative: '.$initiative->name, 'planning', $this->recordEditUrl(InitiativeResource::class, $initiative), 'enterprise:'.$enterpriseId);
        }

        $projects = Project::query()->whereIn('enterprise_id', $enterpriseIds)->orderBy('name')->get();
        $projectIds = $projects->modelKeys();
        foreach ($projects as $project) {
            $projectNode = 'project:'.$project->id;
            $addNode($projectNode, 'Project: '.$project->name, 'planning', $this->recordEditUrl(ProjectResource::class, $project), 'enterprise:'.$project->enterprise_id, $this->statusMetadata($project->status));
            $addNode('group:milestones:'.$project->id, 'Milestones', 'group', '#', $projectNode, ['groupType' => 'milestones', 'projectId' => $project->id]);
            $addNode('group:tasks:project:'.$project->id, 'Tasks', 'group', '#', $projectNode, ['groupType' => 'tasks', 'projectId' => $project->id]);
        }

        $milestones = Milestone::query()
            ->whereIn('enterprise_id', $enterpriseIds)
            ->whereIn('project_id', $projectIds)
            ->orderBy('name')
            ->get();
        foreach ($milestones as $milestone) {
            $addNode(
                'milestone:'.$milestone->id,
                'Milestone: '.$milestone->name,
                'planning',
                $this->recordEditUrl(MilestoneResource::class, $milestone),
                'group:milestones:'.$milestone->project_id,
                $this->statusMetadata($milestone->status, $milestone->due_at),
            );
        }

        $tasks = Task::query()->whereIn('enterprise_id', $enterpriseIds)->orderBy('name')->get();
        $taskIds = $tasks->modelKeys();
        $tasksById = $tasks->keyBy('id');

        foreach ($tasks as $task) {
            $group = $task->project_id === null
                ? 'group:tasks:enterprise:'.$task->enterprise_id
                : 'group:tasks:project:'.$task->project_id;
            $addNode(
                'task:'.$task->id,
                'Task: '.$task->name,
                'supporting',
                $this->recordEditUrl(TaskResource::class, $task),
                $group,
                array_merge($this->statusMetadata($task->status, $task->due_at), ['groupId' => $group]),
            );
        }

        // Preserve Task parent-child hierarchy. Group membership is the fallback for roots.
        foreach ($tasks as $task) {
            $taskNode = 'task:'.$task->id;
            $parent = $task->parent_task_id === null ? null : $tasksById->get($task->parent_task_id);
            if ($parent instanceof Task
                && (int) $parent->enterprise_id === (int) $task->enterprise_id
                && $parent->project_id === $task->project_id) {
                $links[] = ['source' => 'task:'.$parent->id, 'target' => $taskNode, 'value' => 1];
            }
        }

        $taskMorph = (new Task)->getMorphClass();
        $assignments = Assignment::query()
            ->whereIn('enterprise_id', $enterpriseIds)
            ->where('assignable_type', $taskMorph)
            ->whereIn('assignable_id', $taskIds)
            ->with('user')
            ->orderBy('id')
            ->get();
        foreach ($assignments as $assignment) {
            $person = $assignment->user_id
                ? ($assignment->user->name)
                : ($assignment->agent_assignment_id ? 'Agent assignment #'.$assignment->agent_assignment_id : 'Unassigned');
            $addNode('assignment:'.$assignment->id, 'Assignment: '.$person, 'supporting', $this->recordEditUrl(AssignmentResource::class, $assignment), 'task:'.$assignment->assignable_id);
        }

        $decisions = Decision::query()
            ->whereIn('enterprise_id', $enterpriseIds)
            ->whereIn('task_id', $taskIds)
            ->orderBy('id')
            ->get();
        foreach ($decisions as $decision) {
            $addNode('decision:'.$decision->id, 'Decision: '.$decision->title, 'supporting', $this->recordEditUrl(DecisionResource::class, $decision), 'task:'.$decision->task_id);
        }

        $dependencies = Dependency::query()
            ->whereIn('enterprise_id', $enterpriseIds)
            ->where(function (Builder $query) use ($taskMorph, $taskIds): void {
                $query->where(fn (Builder $q) => $q->where('predecessor_type', $taskMorph)->whereIn('predecessor_id', $taskIds))
                    ->orWhere(fn (Builder $q) => $q->where('successor_type', $taskMorph)->whereIn('successor_id', $taskIds));
            })
            ->orderBy('id')
            ->get();
        foreach ($dependencies as $dependency) {
            $taskId = $dependency->predecessor_type === $taskMorph && in_array((int) $dependency->predecessor_id, $taskIds, true)
                ? (int) $dependency->predecessor_id
                : (int) $dependency->successor_id;
            if (isset($known['task:'.$taskId])) {
                $addNode('dependency:'.$dependency->id, 'Dependency: '.$dependency->type, 'supporting', $this->recordEditUrl(DependencyResource::class, $dependency), 'task:'.$taskId);
            }
        }

        $enterpriseDecisions = EnterpriseDecision::query()->whereIn('enterprise_id', $enterpriseIds)->orderBy('id')->get();
        foreach ($enterpriseDecisions as $decision) {
            $addNode('enterprise-decision:'.$decision->id, 'Enterprise Decision: '.$decision->title, 'governance', $this->recordEditUrl(EnterpriseDecisionResource::class, $decision), 'enterprise:'.$decision->enterprise_id);
        }

        $childrenByParent = [];
        $childIds = [];
        foreach ($links as $link) {
            $childrenByParent[$link['source']][] = $link['target'];
            $childIds[$link['target']] = true;
        }

        $nodesById = [];
        foreach ($nodes as $node) {
            $nodesById[$node['name']] = $node;
        }

        $buildNode = function (string $id, array $ancestors = []) use (&$buildNode, $nodesById, $childrenByParent): ?array {
            if (isset($ancestors[$id]) || ! isset($nodesById[$id])) {
                return null;
            }
            $node = $nodesById[$id];
            $node['children'] = [];
            $nextAncestors = $ancestors;
            $nextAncestors[$id] = true;
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $child = $buildNode($childId, $nextAncestors);
                if ($child !== null) {
                    $node['children'][] = $child;
                }
            }

            return $node;
        };

        $roots = [];
        foreach ($nodes as $node) {
            if ($node['type'] !== 'organization' || isset($childIds[$node['name']])) {
                continue;
            }
            $root = $buildNode($node['name']);
            if ($root !== null) {
                $roots[] = $root;
            }
        }

        return $roots === [] ? [] : [[
            'name' => 'root:work-overview',
            'label' => 'Authorized Enterprises',
            'type' => 'root',
            'children' => $roots,
        ]];
    }
}