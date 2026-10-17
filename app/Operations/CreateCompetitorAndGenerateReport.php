<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CreateCompetitorAndGenerateReport implements Operation
{
    public function __construct(
        private readonly CreateCompetitor $createCompetitor,
        private readonly GenerateCompetitiveAnalysisReport $generateReport,
    ) {}

    /** @param array<string, mixed> $input
     * @return array{competitor: Competitor, report: Report}
     */
    public function execute(User $actor, array $input): array
    {
        $enterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) ($input['enterprise_id'] ?? 0));

        $result = DB::transaction(function () use ($actor, $enterprise, $input): array {
            $competitor = $this->createCompetitor->execute($actor, [
                ...$input,
                'enterprise' => $enterprise,
            ]);

            // Empty competitor_ids intentionally selects every current profile in this Enterprise,
            // including the exact version created above, while excluding archived versions.
            $report = $this->generateReport->execute($actor, [
                'enterprise' => $enterprise,
                'competitor_ids' => [],
            ]);

            $sourceRecords = $report->snapshot?->getAttribute('source_records');
            $capturedCompetitors = is_array($sourceRecords)
                ? ($sourceRecords['inputs']['competitors'] ?? [])
                : [];
            $capturedIds = [];

            if (is_array($capturedCompetitors)) {
                foreach ($capturedCompetitors as $capturedCompetitor) {
                    if (is_array($capturedCompetitor) && isset($capturedCompetitor['competitor_id'])) {
                        $capturedIds[] = (int) $capturedCompetitor['competitor_id'];
                    }
                }
            }

            if (! in_array((int) $competitor->getKey(), $capturedIds, true)) {
                throw new LogicException('The generated report did not capture the newly created competitor profile version.');
            }

            return ['competitor' => $competitor, 'report' => $report];
        });

        /** @var array{competitor: Competitor, report: Report} $result */
        return $result;
    }
}
