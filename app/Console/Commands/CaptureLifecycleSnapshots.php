<?php

namespace App\Console\Commands;

use App\Services\Lifecycle\LifecycleHistoryService;
use Illuminate\Console\Command;

final class CaptureLifecycleSnapshots extends Command
{
    protected $signature = 'lifecycle:capture-daily-snapshots';

    protected $description = 'Capture start-of-day and end-of-day lifecycle boundary snapshots';

    public function handle(LifecycleHistoryService $history): int
    {
        $count = $history->captureMidnight();
        $this->info("Processed {$count} lifecycle boundary snapshots.");

        return self::SUCCESS;
    }
}
