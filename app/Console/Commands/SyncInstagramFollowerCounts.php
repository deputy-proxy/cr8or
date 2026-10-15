<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Models\SocialAccount;
use App\Services\Social\InstagramFollowerCountSynchronizer;
use Illuminate\Console\Command;
use Throwable;

final class SyncInstagramFollowerCounts extends Command
{
    protected $signature = 'social-accounts:sync-instagram-follower-counts';

    protected $description = 'Refresh follower counts for connected Instagram professional accounts.';

    public function handle(InstagramFollowerCountSynchronizer $synchronizer): int
    {
        $processed = 0;
        $failed = 0;

        SocialAccount::query()
            ->where('provider', 'instagram')
            ->where('status', SocialAccount::STATUS_ACTIVE)
            ->whereHas('socialIdentity.integrationConnection', fn ($query) => $query
                ->whereIn('provider', ['meta', 'instagram'])
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

        $this->info("Instagram follower counts refreshed: {$processed}; failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}