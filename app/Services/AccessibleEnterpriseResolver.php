<?php

namespace App\Services;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class AccessibleEnterpriseResolver
{
    /**
     * Apply the same organization-membership boundary as EnterprisePolicy::view.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeQuery(Builder $query, User $actor): Builder
    {
        return $query->whereIn(
            'organization_id',
            $actor->memberships()
                ->whereIn('role', [
                    MembershipRole::Owner->value,
                    MembershipRole::Admin->value,
                    MembershipRole::Member->value,
                ])
                ->select('organization_id'),
        );
    }

    /** @return Collection<int, Enterprise> */
    public function forActor(User $actor): Collection
    {
        return $this->scopeQuery(Enterprise::query(), $actor)
            ->orderBy('id')
            ->get()
            ->filter(fn (Enterprise $enterprise): bool => Gate::forUser($actor)->allows('view', $enterprise))
            ->values();
    }
}
