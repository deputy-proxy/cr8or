<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Jobs\SyncEnterpriseGitHubIssues;
use App\Models\Enterprise;
use App\Models\EnterpriseCategory;
use App\Models\EnterpriseGroup;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Publication;
use App\Models\PublicationSchedule;
use App\Models\SocialAccount;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowExecution;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use UnitEnum;

class EnterprisePortfolioDashboard extends Page
{
    use ScopesAuthorizedRecords;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Organization & Access';

    protected static ?string $navigationLabel = 'Enterprise Portfolio';

    protected static ?string $title = 'Enterprise Portfolio';

    protected static ?int $navigationSort = 45;

    protected string $view = 'filament.pages.enterprise-portfolio-dashboard';

    public string $search = '';

    public string $groupFilter = '';

    public string $categoryFilter = '';

    public ?int $selectedEnterpriseId = null;

    public static function canAccess(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function mount(): void
    {
        $user = static::currentUser();
        if (! ($user instanceof User)) {
            abort(403);
        }

        $this->selectedEnterpriseId = $this->authorizedEnterprises()->orderBy('name')->value('id');
    }

    #[On('select-enterprise')]
    public function selectEnterprise(int $enterpriseId): void
    {
        $enterprise = $this->authorizedEnterprises()->whereKey($enterpriseId)->first();
        if ($enterprise === null) {
            throw new NotFoundHttpException;
        }

        $this->selectedEnterpriseId = (int) $enterprise->id;
    }

    public function syncGitHubIssues(): void
    {
        $enterprise = $this->selectedEnterprise();
        if ($enterprise === null || ! Gate::allows('update', $enterprise)) {
            abort(403);
        }

        if (! is_string($enterprise->github_repository) || $enterprise->github_repository === '') {
            $this->addError('githubSync', 'Configure a GitHub repository before synchronizing issues.');

            return;
        }

        SyncEnterpriseGitHubIssues::dispatch((int) $enterprise->id);
        session()->flash('portfolioSyncQueued', 'GitHub issue synchronization queued.');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $organizationIds = static::authorizedOrganizationIds();
        $query = $this->authorizedEnterprises()
            ->with(['group', 'category', 'context'])
            ->withCount([
                'projects',
                'tasks',
                'workItems',
                'milestones',
                'contentItems',
                'events',
                'issues as issues_count' => fn (Builder $builder) => $builder->whereColumn('issues.repository', 'enterprises.github_repository'),
            ])
            ->when(trim($this->search) !== '', function (Builder $builder): void {
                $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $builder->where(function (Builder $nested) use ($term): void {
                    $nested->where('name', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhere('github_repository', 'like', $term)
                        ->orWhere('website_domain', 'like', $term)
                        ->orWhereHas('context', fn (Builder $context) => $context->where('description', 'like', $term)->orWhere('target_market', 'like', $term));
                });
            })
            ->when($this->groupFilter !== '', fn (Builder $builder) => $builder->where('enterprise_group_id', (int) $this->groupFilter))
            ->when($this->categoryFilter !== '', fn (Builder $builder) => $builder->where('enterprise_category_id', (int) $this->categoryFilter))
            ->orderBy('name');

        $dashboardEnterprises = (clone $query)->limit(250)->get();
        $enterprises = $query->paginate(12);
        $graphEnterprises = $this->authorizedEnterprises()->with(['group', 'category'])->orderBy('name')->limit(250)->get();
        $graphIds = $graphEnterprises->modelKeys();
        /** @var array<int, Enterprise> $graphById */
        $graphById = [];
        foreach ($graphEnterprises as $graphEnterprise) {
            $graphById[(int) $graphEnterprise->id] = $graphEnterprise;
        }
        $nodes = [];
        /** @var list<array{source: string, target: string, sourceName: string, targetName: string, type: string, description: string}> $links */
        $links = [];
        foreach ($graphEnterprises as $enterprise) {
            $nodes[] = [
                'id' => 'enterprise:'.$enterprise->id,
                'enterpriseId' => (int) $enterprise->id,
                'name' => (string) $enterprise->name,
                'group' => (string) ($enterprise->group->name ?? 'Ungrouped'),
                'category' => (string) ($enterprise->category->name ?? 'Uncategorized'),
                'status' => (string) $enterprise->status,
            ];
        }
        /** @var array<string, true> $seenLinks */
        $seenLinks = [];
        foreach ($graphEnterprises as $enterprise) {
            $connections = $enterprise->connections ?? [];
            foreach ($connections as $connection) {
                if (! is_array($connection)) {
                    continue;
                }
                $targetId = (int) ($connection['target_enterprise_id'] ?? 0);
                $target = $graphById[$targetId] ?? null;
                if ($target === null || ! in_array($targetId, $graphIds, true) || (int) $target->organization_id !== (int) $enterprise->organization_id) {
                    continue;
                }

                $sourceNode = 'enterprise:'.$enterprise->id;
                $targetNode = 'enterprise:'.$targetId;
                $direction = $connection['direction'] ?? 'outgoing';
                $edges = match ($direction) {
                    'incoming' => [[$targetNode, $sourceNode]],
                    'bidirectional' => [[$sourceNode, $targetNode], [$targetNode, $sourceNode]],
                    default => [[$sourceNode, $targetNode]],
                };
                foreach ($edges as [$source, $destination]) {
                    $key = implode('|', [$source, $destination, (string) ($connection['type'] ?? 'related_to')]);
                    if (isset($seenLinks[$key])) {
                        continue;
                    }
                    $seenLinks[$key] = true;
                    $sourceId = (int) str_replace('enterprise:', '', $source);
                    $destinationId = (int) str_replace('enterprise:', '', $destination);
                    $links[] = [
                        'source' => $source,
                        'target' => $destination,
                        'sourceName' => (string) (($graphById[$sourceId]->name ?? $source)),
                        'targetName' => (string) (($graphById[$destinationId]->name ?? $destination)),
                        'type' => (string) ($connection['type'] ?? 'related_to'),
                        'description' => is_string($connection['description'] ?? null) ? $connection['description'] : '',
                    ];
                    if (count($links) >= 1000) {
                        break;
                    }
                }
                if (count($links) >= 1000) {
                    break;
                }
            }
            if (count($links) >= 1000) {
                break;
            }
        }

        $selectedEnterprise = $this->selectedEnterprise();
        if ($selectedEnterprise !== null) {
            $selectedEnterprise->loadCount([
                'projects',
                'tasks',
                'workItems',
                'milestones',
                'contentItems',
                'issues as issues_count' => fn (Builder $builder) => $builder->whereColumn('issues.repository', 'enterprises.github_repository'),
            ]);
            $selectedEnterprise->setRelation('recentEvents', $selectedEnterprise->events()->orderByDesc('occurred_at')->limit(10)->get());
            $selectedEnterprise->setRelation(
                'recentIssues',
                $selectedEnterprise->issues()
                    ->when(is_string($selectedEnterprise->github_repository) && $selectedEnterprise->github_repository !== '', fn (Builder $builder) => $builder->where('repository', $selectedEnterprise->github_repository), fn (Builder $builder) => $builder->whereRaw('1 = 0'))
                    ->orderByDesc('github_updated_at')->limit(8)->get(),
            );
            $selectedEnterprise->setRelation('recentContent', $selectedEnterprise->contentItems()->orderByDesc('updated_at')->limit(8)->get());
            $selectedEnterprise->setRelation('recentPublications', Publication::query()->with('contentItem')->where('enterprise_id', $selectedEnterprise->id)->orderByDesc('updated_at')->limit(8)->get());
            $selectedEnterprise->setRelation('upcomingSchedules', PublicationSchedule::query()->with('publication.contentItem')->where('enterprise_id', $selectedEnterprise->id)->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->limit(8)->get());
            $selectedEnterprise->setRelation('recentWorkflowExecutions', WorkflowExecution::query()->with('workflow')->where('enterprise_id', $selectedEnterprise->id)->orderByDesc('created_at')->limit(8)->get());
        }

        $groups = EnterpriseGroup::query()->whereIn('organization_id', $organizationIds)->orderBy('sort_order')->orderBy('name')->get();
        $categories = EnterpriseCategory::query()->whereIn('organization_id', $organizationIds)->orderBy('sort_order')->orderBy('name')->get();
        $dashboardStreams = $groups->map(fn (EnterpriseGroup $group): array => [
            'id' => 'group:'.$group->id,
            'title' => $group->name,
            'description' => $group->description ?: 'Enterprises in this group',
        ])->values();
        if ($dashboardEnterprises->contains(fn (Enterprise $enterprise): bool => $enterprise->enterprise_group_id === null)) {
            $dashboardStreams->push(['id' => 'ungrouped', 'title' => 'Ungrouped', 'description' => 'Enterprises without a group']);
        }

        $dashboardProjects = $dashboardEnterprises->map(fn (Enterprise $enterprise): array => [
            'project_id' => (string) $enterprise->id,
            'enterprise_id' => (int) $enterprise->id,
            'stream_id' => $enterprise->enterprise_group_id === null ? 'ungrouped' : 'group:'.$enterprise->enterprise_group_id,
            'name' => (string) $enterprise->name,
            'category' => (string) ($enterprise->category->name ?? 'Uncategorized'),
            'target' => (string) ($enterprise->context->target_market ?? ''),
            'description' => (string) ($enterprise->context->description ?? ''),
            'status' => (string) $enterprise->status,
            'website_domain' => (string) ($enterprise->website_domain ?? ''),
            'github_repository' => (string) ($enterprise->github_repository ?? ''),
            'projects_count' => (int) ($enterprise->projects_count ?? 0),
            'tasks_count' => (int) ($enterprise->tasks_count ?? 0),
            'work_items_count' => (int) ($enterprise->work_items_count ?? 0),
            'content_items_count' => (int) ($enterprise->content_items_count ?? 0),
        ])->values();

        $enterpriseIds = $dashboardEnterprises->modelKeys();
        $dashboardRelations = collect($links)->map(function (array $link): array {
            return [
                'project_id' => (string) str($link['source'])->after('enterprise:'),
                'target_project_id' => (string) str($link['target'])->after('enterprise:'),
                'source_name' => $link['sourceName'],
                'target_name' => $link['targetName'],
                'type' => (string) $link['type'],
                'description' => (string) $link['description'],
                'direction' => 'outgoing',
            ];
        })->values();

        $dashboardEvents = Event::query()->whereIn('enterprise_id', $enterpriseIds)
            ->whereIn('organization_id', $organizationIds)
            ->orderByDesc('occurred_at')->limit(300)->get()
            ->map(static function (EloquentModel $event): array {
                $occurredAt = $event->getAttribute('occurred_at');

                return [
                    'event_id' => (string) $event->getKey(),
                    'project_id' => (string) $event->getAttribute('enterprise_id'),
                    'description' => (string) $event->getAttribute('description'),
                    'timestamp' => $occurredAt ? Carbon::parse($occurredAt)->toISOString() : null,
                ];
            })->values();

        $dashboardIssues = Issue::query()->whereIn('enterprise_id', $enterpriseIds)
            ->where('state', 'open')->orderByDesc('github_updated_at')->limit(500)->get()
            ->map(static function (EloquentModel $issue): array {
                $labels = $issue->getAttribute('labels');
                $createdAt = $issue->getAttribute('github_created_at');

                return [
                    'issue_id' => (string) $issue->getKey(),
                    'project_id' => (string) $issue->getAttribute('enterprise_id'),
                    'number' => (int) $issue->getAttribute('number'),
                    'title' => (string) $issue->getAttribute('title'),
                    'state' => (string) $issue->getAttribute('state'),
                    'labels' => is_array($labels) ? $labels : [],
                    'author' => (string) ($issue->getAttribute('author_login') ?? ''),
                    'created_at' => $createdAt ? Carbon::parse($createdAt)->diffForHumans() : '',
                    'url' => (string) ($issue->getAttribute('url') ?? ''),
                ];
            })->values();

        $dashboardScheduledPosts = Publication::query()->with(['contentItem', 'channel'])
            ->whereIn('enterprise_id', $enterpriseIds)->where('status', Publication::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')->orderBy('scheduled_at')->limit(300)->get()
            ->map(fn (Publication $publication): array => [
                'post_id' => (string) $publication->id,
                'project_id' => (string) $publication->enterprise_id,
                'title' => (string) ($publication->contentItem->title ?? 'Untitled content'),
                'platform' => (string) ($publication->channel->name ?? 'Channel'),
                'status' => (string) $publication->status,
                'scheduled_at' => $publication->scheduled_at?->toISOString(),
                'date' => $publication->scheduled_at?->diffForHumans() ?? '',
            ])->values();

        $dashboardTasks = Task::query()->whereIn('enterprise_id', $enterpriseIds)
            ->whereNotIn('status', ['completed', 'done', 'cancelled'])
            ->orderByRaw('due_at is null')->orderBy('due_at')->limit(500)->get()
            ->map(fn (Task $task): array => [
                'task_id' => (string) $task->id,
                'project_id' => (string) $task->enterprise_id,
                'title' => (string) $task->name,
                'priority' => (string) ($task->priority ?? 'medium'),
                'assignee' => '',
                'due_date' => $task->due_at ? \Illuminate\Support\Carbon::parse($task->due_at)->diffForHumans() : 'No due date',
                'status' => (string) $task->status,
            ])->values();

        $dashboardWorkflows = WorkflowExecution::query()->with('workflow')
            ->whereIn('enterprise_id', $enterpriseIds)->orderByDesc('created_at')->limit(300)->get()
            ->map(fn (WorkflowExecution $execution): array => [
                'workflow_id' => (string) $execution->id,
                'project_id' => (string) $execution->enterprise_id,
                'name' => (string) ($execution->workflow->name ?? 'Workflow execution'),
                'status' => (string) $execution->status,
                'created_at' => $execution->created_at?->diffForHumans() ?? '',
            ])->values();

        $activeSocialAccounts = SocialAccount::query()->whereIn('enterprise_id', $enterpriseIds)
            ->where('status', SocialAccount::STATUS_ACTIVE)->get(['enterprise_id', 'provider']);
        $dashboardSocialNetworks = $activeSocialAccounts->pluck('provider')->filter()
            ->unique()->values();
        $dashboardSocialFollowers = $activeSocialAccounts->groupBy('enterprise_id')
            ->map(static function ($accounts, int|string $enterpriseId) use ($dashboardSocialNetworks): array {
                $counts = ['project_id' => (string) $enterpriseId];
                foreach ($dashboardSocialNetworks as $network) {
                    if (is_string($network)) {
                        $counts[$network] = $accounts->where('provider', $network)->count();
                    }
                }

                return $counts;
            })->values();

        return [
            'enterprises' => $enterprises,
            'groups' => $groups,
            'categories' => $categories,
            'dashboardStreams' => $dashboardStreams,
            'dashboardProjects' => $dashboardProjects,
            'dashboardRelations' => $dashboardRelations,
            'dashboardEvents' => $dashboardEvents,
            'dashboardIssues' => $dashboardIssues,
            'dashboardScheduledPosts' => $dashboardScheduledPosts,
            'dashboardTasks' => $dashboardTasks,
            'dashboardWorkflows' => $dashboardWorkflows,
            'dashboardSocialNetworks' => $dashboardSocialNetworks,
            'dashboardSocialFollowers' => $dashboardSocialFollowers,
            'graphData' => ['nodes' => $nodes, 'links' => $links],
            'graphEnterpriseTotal' => $this->authorizedEnterprises()->count(),
            'selectedEnterprise' => $selectedEnterprise,
        ];
    }

    private function selectedEnterprise(): ?Enterprise
    {
        if ($this->selectedEnterpriseId === null) {
            return null;
        }

        return $this->authorizedEnterprises()->with(['group', 'category', 'context'])
            ->whereKey($this->selectedEnterpriseId)->first();
    }

    /** @return Builder<Enterprise> */
    private function authorizedEnterprises(): Builder
    {
        return Enterprise::query()->whereIn('organization_id', static::authorizedOrganizationIds());
    }
}
