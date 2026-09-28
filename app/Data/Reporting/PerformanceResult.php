<?php

namespace App\Data\Reporting;

use App\Models\Report;
use Illuminate\Support\Carbon;

final readonly class PerformanceResult
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public Report $report,
        public array $metrics,
        public array $provenance,
        public Carbon $periodStart,
        public Carbon $periodEnd,
    ) {}
}