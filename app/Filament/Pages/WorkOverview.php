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

    public function mount(): void
    {
        $user = static::currentUser();

        if (! ($user instanceof User)) {
            abort(403);
        }

        $organizationIds = static::authorizedOrganizationIds();

        $this->overview = [
            ['label' => 'Initiatives', 'count' => Initiative::query()
                ->whereHas('plan.strategy.objective', fn (Builder $query) => $query->whereIn('enterprise_id', Enterprise::query()->whereIn('organization_id', $organizationIds)->select('id')))
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
}
