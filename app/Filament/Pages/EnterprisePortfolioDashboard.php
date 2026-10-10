<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Jobs\SyncEnterpriseGitHubIssues;
use App\Models\Enterprise;
use App\Models\EnterpriseCategory;
use App\Models\EnterpriseGroup;
use App\Models\Publication;
use App\Models\PublicationSchedule;
use App\Models\User;
use App\Models\WorkflowExecution;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
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
            $selectedEnterprise->loadCount(['projects', 'tasks', 'workItems', 'milestones', 'contentItems']);
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

        return [
            'enterprises' => $enterprises,
            'groups' => EnterpriseGroup::query()->whereIn('organization_id', $organizationIds)->orderBy('sort_order')->orderBy('name')->get(),
            'categories' => EnterpriseCategory::query()->whereIn('organization_id', $organizationIds)->orderBy('sort_order')->orderBy('name')->get(),
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
