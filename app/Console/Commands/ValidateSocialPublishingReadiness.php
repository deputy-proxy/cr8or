<?php

namespace App\Console\Commands;

use App\Services\SocialPublishingReadinessValidator;
use Illuminate\Console\Command;

final class ValidateSocialPublishingReadiness extends Command
{
    protected $signature = 'social:publishing-readiness';

    protected $description = 'Validate enabled social publishing configuration without displaying credentials.';

    public function handle(SocialPublishingReadinessValidator $validator): int
    {
        /** @var array<string, mixed> $adapterConfiguration */
        $adapterConfiguration = config('publishing.adapters', []);
        $enabled = [];
        foreach ($adapterConfiguration as $network => $adapter) {
            if (is_array($adapter) && ($adapter['enabled'] ?? false) === true) {
                $enabled[] = (string) $network;
            }
        }

        if ($enabled === []) {
            $this->warn('No native social publishing adapters are enabled. All networks remain disabled by default.');

            return self::SUCCESS;
        }

        $errors = $validator->validate();
        foreach ($enabled as $network) {
            $networkErrors = $errors[$network] ?? [];
            if ($networkErrors === []) {
                $this->info("{$network}: configuration preflight passed; provider approval and live-account verification remain separate gates.");

                continue;
            }

            foreach ($networkErrors as $error) {
                $this->error("{$network}: {$error}");
            }
        }

        foreach ($errors['publishing'] ?? [] as $error) {
            $this->error("publishing: {$error}");
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
