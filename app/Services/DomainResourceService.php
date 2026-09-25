<?php

namespace App\Services;

use App\Models\Audience;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentSeries;
use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\Initiative;
use App\Models\Kpi;
use App\Models\MarketingStrategy;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class DomainResourceService
{
    /** @param array<string, mixed> $attributes */
    public function createObjective(User $actor, Enterprise $enterprise, array $attributes): Objective
    {
        Gate::forUser($actor)->authorize('createForEnterprise', [Objective::class, $enterprise]);
        $this->validateObjectiveReferences($enterprise, $attributes);

        return $enterprise->objectives()->create([
            'goal_id' => $attributes['goal_id'] ?? null,
            'kpi_id' => $attributes['kpi_id'] ?? null,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateObjective(User $actor, Objective $objective, array $attributes): Objective
    {
        Gate::forUser($actor)->authorize('update', $objective);
        $this->validateObjectiveReferences($objective->enterprise, $attributes);

        $objective->update(array_intersect_key($attributes, array_flip([
            'goal_id', 'kpi_id', 'name', 'description',
        ])));

        return $objective->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function createMarketingStrategy(User $actor, Enterprise $enterprise, array $attributes): MarketingStrategy
    {
        Gate::forUser($actor)->authorize('createForEnterprise', [MarketingStrategy::class, $enterprise]);

        return $enterprise->marketingStrategies()->create([
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => MarketingStrategy::STATUS_DRAFT,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateMarketingStrategy(User $actor, MarketingStrategy $strategy, array $attributes): MarketingStrategy
    {
        Gate::forUser($actor)->authorize('update', $strategy);

        $strategy->update(array_intersect_key($attributes, array_flip(['name', 'description'])));

        if (isset($attributes['status'])) {
            $strategy->transitionTo($attributes['status'])->save();
        }

        return $strategy->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function createCampaign(User $actor, Enterprise $enterprise, array $attributes): Campaign
    {
        Gate::forUser($actor)->authorize('createForEnterprise', [Campaign::class, $enterprise]);

        $strategy = MarketingStrategy::query()->findOrFail((int) $attributes['marketing_strategy_id']);
        if ((int) $strategy->enterprise_id !== (int) $enterprise->getKey()) {
            throw new LogicException('The marketing strategy must belong to the selected enterprise.');
        }

        return $enterprise->campaigns()->create([
            'marketing_strategy_id' => $strategy->getKey(),
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => Campaign::STATUS_DRAFT,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateCampaign(User $actor, Campaign $campaign, array $attributes): Campaign
    {
        Gate::forUser($actor)->authorize('update', $campaign);

        if (isset($attributes['marketing_strategy_id'])) {
            $strategy = MarketingStrategy::query()->findOrFail((int) $attributes['marketing_strategy_id']);
            if ((int) $strategy->enterprise_id !== (int) $campaign->enterprise_id) {
                throw new LogicException('The marketing strategy must belong to the campaign enterprise.');
            }
            $campaign->marketing_strategy_id = $strategy->getKey();
        }

        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $campaign->{$field} = $attributes[$field];
            }
        }

        if (isset($attributes['status'])) {
            $campaign->transitionTo($attributes['status']);
        }

        $campaign->save();

        return $campaign->refresh();
    }

    public function transitionCampaign(User $actor, Campaign $campaign, string $status): Campaign
    {
        Gate::forUser($actor)->authorize('update', $campaign);
        $campaign->transitionTo($status)->save();

        return $campaign->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function createContentSeries(User $actor, Campaign $campaign, array $attributes): ContentSeries
    {
        Gate::forUser($actor)->authorize('createForCampaign', [ContentSeries::class, $campaign]);

        return $campaign->contentSeries()->create([
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => ContentSeries::STATUS_DRAFT,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateContentSeries(User $actor, ContentSeries $series, array $attributes): ContentSeries
    {
        Gate::forUser($actor)->authorize('update', $series);

        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $series->{$field} = $attributes[$field];
            }
        }

        if (isset($attributes['status'])) {
            $series->transitionTo($attributes['status']);
        }

        $series->save();

        return $series->refresh();
    }

    public function transitionContentSeries(User $actor, ContentSeries $series, string $status): ContentSeries
    {
        Gate::forUser($actor)->authorize('update', $series);
        $series->transitionTo($status)->save();

        return $series->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function createAudience(User $actor, Enterprise $enterprise, array $attributes): Audience
    {
        Gate::forUser($actor)->authorize('createForEnterprise', [Audience::class, $enterprise]);

        return $enterprise->audiences()->create([
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => Audience::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateAudience(User $actor, Audience $audience, array $attributes): Audience
    {
        Gate::forUser($actor)->authorize('update', $audience);

        $audience->update(array_intersect_key($attributes, array_flip(['name', 'description'])));
        if (isset($attributes['status'])) {
            if (! in_array($attributes['status'], [Audience::STATUS_ACTIVE, Audience::STATUS_ARCHIVED], true)) {
                throw new LogicException('Invalid audience status.');
            }
            $audience->status = $attributes['status'];
            $audience->save();
        }

        return $audience->refresh();
    }

    public function archiveAudience(User $actor, Audience $audience): Audience
    {
        Gate::forUser($actor)->authorize('update', $audience);

        if ($audience->status === Audience::STATUS_ARCHIVED) {
            return $audience;
        }

        $audience->status = Audience::STATUS_ARCHIVED;
        $audience->save();

        return $audience->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function createChannel(User $actor, Enterprise $enterprise, array $attributes): Channel
    {
        Gate::forUser($actor)->authorize('createForEnterprise', [Channel::class, $enterprise]);

        return $enterprise->channels()->create([
            'name' => $attributes['name'],
            'type' => $attributes['type'],
            'status' => Channel::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateChannel(User $actor, Channel $channel, array $attributes): Channel
    {
        Gate::forUser($actor)->authorize('update', $channel);

        $channel->update(array_intersect_key($attributes, array_flip(['name', 'type'])));
        if (isset($attributes['status'])) {
            if (! in_array($attributes['status'], [Channel::STATUS_ACTIVE, Channel::STATUS_ARCHIVED], true)) {
                throw new LogicException('Invalid channel status.');
            }
            $channel->status = $attributes['status'];
            $channel->save();
        }

        return $channel->refresh();
    }

    public function archiveChannel(User $actor, Channel $channel): Channel
    {
        Gate::forUser($actor)->authorize('update', $channel);

        if ($channel->status === Channel::STATUS_ARCHIVED) {
            return $channel;
        }

        $channel->status = Channel::STATUS_ARCHIVED;
        $channel->save();

        return $channel->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function connectSocialAccount(User $actor, Enterprise $enterprise, array $attributes): SocialAccount
    {
        Gate::forUser($actor)->authorize('createForEnterprise', [SocialAccount::class, $enterprise]);

        $channel = Channel::query()->findOrFail((int) $attributes['channel_id']);
        if ((int) $channel->enterprise_id !== (int) $enterprise->getKey()) {
            throw new LogicException('The channel must belong to the selected enterprise.');
        }

        return SocialAccount::query()->create([
            'enterprise_id' => $enterprise->getKey(),
            'channel_id' => $channel->getKey(),
            'provider' => $attributes['provider'],
            'name' => $attributes['name'],
            'external_id' => $attributes['external_id'],
            'status' => SocialAccount::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateSocialAccount(User $actor, SocialAccount $account, array $attributes): SocialAccount
    {
        Gate::forUser($actor)->authorize('update', $account);

        $account->update(array_intersect_key($attributes, array_flip(['name', 'channel_id'])));
        if (isset($attributes['channel_id'])) {
            $channel = Channel::query()->findOrFail((int) $attributes['channel_id']);
            if ((int) $channel->enterprise_id !== (int) $account->enterprise_id) {
                throw new LogicException('The channel must belong to the social account enterprise.');
            }
            $account->channel_id = $channel->getKey();
            $account->save();
        }

        return $account->refresh();
    }

    public function disconnectSocialAccount(User $actor, SocialAccount $account): SocialAccount
    {
        Gate::forUser($actor)->authorize('delete', $account);

        if ($account->status === SocialAccount::STATUS_DISABLED) {
            return $account;
        }

        $account->status = SocialAccount::STATUS_DISABLED;
        $account->save();

        return $account->refresh();
    }

    /** @param array<string, mixed> $attributes */
    public function createProject(User $actor, Enterprise $enterprise, array $attributes): Project
    {
        Gate::forUser($actor)->authorize('createForEnterprise', [Project::class, $enterprise]);
        $this->validateProjectReferences($enterprise, $attributes);

        return $enterprise->projects()->create([
            'strategy_id' => $attributes['strategy_id'] ?? null,
            'plan_id' => $attributes['plan_id'] ?? null,
            'initiative_id' => $attributes['initiative_id'] ?? null,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'planned',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateProject(User $actor, Project $project, array $attributes): Project
    {
        Gate::forUser($actor)->authorize('update', $project);
        $this->validateProjectReferences($project->enterprise, $attributes);

        $project->update(array_intersect_key($attributes, array_flip([
            'strategy_id', 'plan_id', 'initiative_id', 'name', 'description', 'status',
        ])));

        return $project->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function validateObjectiveReferences(Enterprise $enterprise, array $attributes): void
    {
        if (array_key_exists('goal_id', $attributes) && $attributes['goal_id'] !== null) {
            $goal = Goal::query()->findOrFail((int) $attributes['goal_id']);
            if ((int) $goal->enterprise_id !== (int) $enterprise->getKey()) {
                throw new LogicException('The goal must belong to the selected enterprise.');
            }
        }

        if (array_key_exists('kpi_id', $attributes) && $attributes['kpi_id'] !== null) {
            $kpi = Kpi::query()->findOrFail((int) $attributes['kpi_id']);
            if ((int) $kpi->enterprise_id !== (int) $enterprise->getKey()) {
                throw new LogicException('The KPI must belong to the selected enterprise.');
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    private function validateProjectReferences(Enterprise $enterprise, array $attributes): void
    {
        if (isset($attributes['strategy_id'])) {
            $strategy = Strategy::query()->with('objective')->findOrFail((int) $attributes['strategy_id']);
            if ((int) $strategy->objective->enterprise_id !== (int) $enterprise->getKey()) {
                throw new LogicException('The strategy must belong to the selected enterprise.');
            }
        }

        if (isset($attributes['plan_id'])) {
            $plan = Plan::query()->with('strategy.objective')->findOrFail((int) $attributes['plan_id']);
            if ((int) $plan->strategy->objective->enterprise_id !== (int) $enterprise->getKey()) {
                throw new LogicException('The plan must belong to the selected enterprise.');
            }
        }

        if (isset($attributes['initiative_id'])) {
            $initiative = Initiative::query()->with('plan.strategy.objective')->findOrFail((int) $attributes['initiative_id']);
            if ((int) $initiative->plan->strategy->objective->enterprise_id !== (int) $enterprise->getKey()) {
                throw new LogicException('The initiative must belong to the selected enterprise.');
            }
        }
    }
}
