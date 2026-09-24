<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\MultiAgentBusinessReportingService;
use Filament\Pages\Page;
use UnitEnum;

class AgentCollaborationReport extends Page
{
    use ScopesPhaseOneRecords;

    protected static string|\BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Agent Collaboration Report';

    protected static string|UnitEnum|null $navigationGroup = 'Agents';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.agent-collaboration-report';

    /** @var array<int, array<string, mixed>> */
    public array $reports = [];

    public function mount(): void
    {
        $user = static::currentUser();

        if (! $user instanceof User) {
            abort(403);
        }

        $this->reports = Enterprise::query()
            ->whereIn('organization_id', static::authorizedOrganizationIds())
            ->orderBy('name')
            ->get()
            ->map(fn (Enterprise $enterprise): array => app(MultiAgentBusinessReportingService::class)->generate($user, $enterprise))
            ->all();
    }

    public static function canAccess(): bool
    {
        return auth()->check() && static::authorizedOrganizationIds()->exists();
    }
}
