<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\SocialAccount;
use App\Services\Social\FacebookFollowerCountSynchronizer;
use Illuminate\Console\Command;
use Throwable;

final class SyncFacebookFollowerCounts extends Command
{
    protected $signature = 'social-accounts:sync-facebook-follower-counts';

    protected $description = 'Refresh follower counts for connected Facebook Pages.';

    public function handle(FacebookFollowerCountSynchronizer $synchronizer): int
    {
        $processed = 0;
        $failed = 0;

        SocialAccount::query()
            ->where('provider', 'facebook')
            ->where('status', SocialAccount::STATUS_ACTIVE)
            ->whereHas('socialIdentity.integrationConnection', fn ($query) => $query
                ->where('provider', 'meta')
                ->where('status', IntegrationConnection::STATUS_ACTIVE))
            ->with(['enterprise', 'channel', 'socialIdentity.integrationConnection'])
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($synchronizer, &$processed, &$failed): void {
                foreach ($accounts as $account) {
                    try {
                        $synchronizer->synchronize($account);
                        $processed++;
                    } catch (Throwable $exception) {
                        $failed++;
                        report($exception);
                        $this->warn("Follower count refresh failed for social account {$account->getKey()} (".class_basename($exception).').');
                    }
                }
            });

        $this->info("Facebook follower counts refreshed: {$processed}; failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
