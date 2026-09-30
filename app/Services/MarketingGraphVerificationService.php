<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Audience;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Script;
use Illuminate\Support\Facades\Gate;

final class MarketingGraphVerificationService
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function verify(\App\Models\User $actor, Enterprise $enterprise, array $input): array
    {
        Gate::forUser($actor)->authorize('view', $enterprise);

        $checks = [
            'audience_ids' => fn (array $ids): int => Audience::query()->where('enterprise_id', $enterprise->getKey())->whereIn('id', $ids)->count(),
            'campaign_ids' => fn (array $ids): int => Campaign::query()->where('enterprise_id', $enterprise->getKey())->whereIn('id', $ids)->count(),
            'content_series_ids' => fn (array $ids): int => ContentSeries::query()->whereHas('campaign', fn ($q) => $q->where('enterprise_id', $enterprise->getKey()))->whereIn('id', $ids)->count(),
            'content_item_ids' => fn (array $ids): int => ContentItem::query()->where('enterprise_id', $enterprise->getKey())->whereIn('id', $ids)->count(),
            'script_ids' => fn (array $ids): int => Script::query()->whereHas('contentItem', fn ($q) => $q->where('enterprise_id', $enterprise->getKey()))->whereIn('id', $ids)->count(),
            'asset_ids' => fn (array $ids): int => Asset::query()->where('enterprise_id', $enterprise->getKey())->whereIn('id', $ids)->where('status', Asset::STATUS_PENDING)->count(),
        ];

        $missing = [];
        $verified = [];
        foreach ($checks as $key => $query) {
            $ids = array_values(array_unique(array_filter(array_map('intval', is_array($input[$key] ?? null) ? $input[$key] : []))));
            if ($ids === []) {
                $missing[$key] = ['expected' => 1, 'found' => 0];

                continue;
            }
            $found = $query($ids);
            $verified[$key] = $found;
            if ($found !== count($ids)) {
                $missing[$key] = ['expected' => count($ids), 'found' => $found];
            }
        }

        $strategyId = (int) ($input['marketing_strategy_id'] ?? 0);
        $strategyExists = $strategyId > 0 && MarketingStrategy::query()->where('enterprise_id', $enterprise->getKey())->whereKey($strategyId)->exists();
        $verified['marketing_strategy_id'] = $strategyExists ? $strategyId : null;
        if (! $strategyExists) {
            $missing['marketing_strategy_id'] = ['expected' => $strategyId ?: 1, 'found' => 0];
        }

        return [
            'verification_passed' => $missing === [],
            'enterprise_id' => $enterprise->getKey(),
            'verified' => $verified,
            'missing' => $missing,
        ];
    }
}
