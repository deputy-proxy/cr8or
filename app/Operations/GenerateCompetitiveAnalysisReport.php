<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\Report;
use App\Models\User;
use App\Services\CompetitiveAnalysisReportingService;
use Illuminate\Validation\ValidationException;

final class GenerateCompetitiveAnalysisReport implements Operation
{
    public function __construct(private readonly CompetitiveAnalysisReportingService $reports) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): Report
    {
        $enterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) ($input['enterprise_id'] ?? 0));

        $competitorIds = $input['competitor_ids'] ?? [];
        if (! is_array($competitorIds) || ! array_is_list($competitorIds)) {
            throw ValidationException::withMessages(['competitor_ids' => 'Competitor IDs must be a list.']);
        }

        return $this->reports->generate($actor, $enterprise, $competitorIds);
    }
}