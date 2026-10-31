<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\Dashboard\OverviewDashboardData;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class WorkOverview extends Page
{
    use ScopesAuthorizedRecords;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Overview';

    protected static string|UnitEnum|null $navigationGroup = 'Strategy & Execution Planning';

    protected static ?int $navigationSort = 125;

    protected string $view = 'filament.pages.work-overview';

    protected Width|string|null $maxContentWidth = Width::Full;

    public ?int $selectedEnterpriseId = null;

    public string $activityRange = '14_days';

    public function mount(): void
    {
        $user = static::currentUser();

        if (! ($user instanceof User)) {
            abort(403);
        }

        $this->selectedEnterpriseId = $this->authorizedEnterprises()->orderBy('name')->value('id');
    }

    public function selectEnterprise(int $enterpriseId): void
    {
        if (! $this->authorizedEnterprises()->whereKey($enterpriseId)->exists()) {
            abort(404);
        }

        $this->selectedEnterpriseId = $enterpriseId;
    }

    public function setActivityRange(string $range): void
    {
        if (! in_array($range, ['today', '14_days'], true)) {
            abort(422);
        }

        $this->activityRange = $range;
        $this->refreshDashboard();
    }

    public function refreshDashboard(): void
    {
        if ($this->selectedEnterpriseId !== null && ! $this->authorizedEnterprises()->whereKey($this->selectedEnterpriseId)->exists()) {
            $this->selectedEnterpriseId = $this->authorizedEnterprises()->orderBy('name')->value('id');
        }
    }

    protected function getViewData(): array
    {
        $enterprise = $this->selectedEnterprise();
        $enterprises = $this->authorizedEnterprises()->orderBy('name')->get(['id', 'name']);

        if ($enterprise === null) {
            return [
                'enterprises' => $enterprises,
                'selectedEnterprise' => null,
                'summaryCards' => [],
                'listCards' => [],
                'activityAvailable' => false,
                'activityRangeLabel' => $this->activityRange === 'today' ? 'Today' : 'Last 14 days',
                'activityDays' => [],
                'lifecycleChart' => ['available' => false, 'entities' => [], 'limitations' => [], 'rangeLabel' => 'Last 14 days'],
                'activityPanels' => [],
            ];
        }

        return array_merge(app(OverviewDashboardData::class)->forEnterprise($enterprise, $this->activityRange), [
            'enterprises' => $enterprises,
            'selectedEnterprise' => $enterprise,
        ]);
    }

    private function selectedEnterprise(): ?Enterprise
    {
        if ($this->selectedEnterpriseId === null) {
            return null;
        }

        return $this->authorizedEnterprises()->whereKey($this->selectedEnterpriseId)->first();
    }

    /** @return Builder<Enterprise> */
    private function authorizedEnterprises(): Builder
    {
        return Enterprise::query()->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public static function canAccess(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }
}
