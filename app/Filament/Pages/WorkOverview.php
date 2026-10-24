<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\Dashboard\OverviewDashboardData;
use Filament\Pages\Page;
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

    /** @var array<int, array{label: string, count: int, url: string}> */
    public array $overview = [];

    public ?int $selectedEnterpriseId = null;

    public function mount(): void
    {
        $user = static::currentUser();

        if (! ($user instanceof User)) {
            abort(403);
        }

        $this->selectedEnterpriseId = $this->authorizedEnterprises()->orderBy('name')->value('id');
        $this->refreshDashboardData();
    }

    public function selectEnterprise(int $enterpriseId): void
    {
        if (! $this->authorizedEnterprises()->whereKey($enterpriseId)->exists()) {
            abort(404);
        }

        $this->selectedEnterpriseId = $enterpriseId;
        $this->refreshDashboardData();
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
                'activityPanels' => [],
                'lifecycleHistory' => [],
            ];
        }

        return array_merge(app(OverviewDashboardData::class)->forEnterprise($enterprise), [
            'enterprises' => $enterprises,
            'selectedEnterprise' => $enterprise,
        ]);
    }

    private function refreshDashboardData(): void
    {
        $enterprise = $this->selectedEnterprise();
        $this->overview = $enterprise === null
            ? []
            : array_map(
                static fn (array $card): array => [
                    'label' => $card['label'],
                    'count' => $card['count'] ?? 0,
                    'url' => $card['url'] ?? '#',
                ],
                app(OverviewDashboardData::class)->summaryCards($enterprise),
            );
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