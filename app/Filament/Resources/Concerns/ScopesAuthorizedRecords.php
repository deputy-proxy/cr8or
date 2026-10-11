<?php

namespace App\Filament\Resources\Concerns;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

trait ScopesAuthorizedRecords
{
    protected static function currentUser(): ?User
    {
        $u = auth()->user();

        return $u instanceof User ? $u : null;
    }

    /** @return Builder<Membership> */
    protected static function authorizedOrganizationIds(): Builder
    {
        return Membership::query()->select('organization_id')->where('user_id', static::currentUser()?->getKey() ?? 0);
    }

    /** @return Builder<Membership> */
    protected static function manageableOrganizationIds(): Builder
    {
        return Membership::query()->select('organization_id')->where('user_id', static::currentUser()?->getKey() ?? 0)->whereIn('role', [MembershipRole::Owner->value, MembershipRole::Admin->value]);
    }

    /** @return Builder<Enterprise> */
    protected static function authorizedEnterpriseIds(): Builder
    {
        return Enterprise::query()
            ->select('enterprises.id')
            ->whereIn('organization_id', static::authorizedOrganizationIds());
    }

    /** @return Builder<Enterprise> */
    protected static function manageableEnterpriseIds(): Builder
    {
        return Enterprise::query()->select('enterprises.id')->whereIn('organization_id', static::manageableOrganizationIds());
    }

    protected static function canManageAnyOrganization(): bool
    {
        return static::manageableOrganizationIds()->exists();
    }

    protected static function canManageAnyEnterprise(): bool
    {
        return static::manageableEnterpriseIds()->exists();
    }

    /**
     * Apply the default ownership boundary for resources whose model exposes
     * direct organization, enterprise, or user ownership columns. Unknown
     * ownership shapes fail closed; indirect ownership must be handled explicitly.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function scopeAuthorizedOwnership(Builder $query): Builder
    {
        $user = static::currentUser();

        if ($user === null || ! $user->memberships()->exists()) {
            return $query->whereRaw('1 = 0');
        }

        $model = $query->getModel();

        if (class_basename($model) === 'WorkflowVersion') {
            return $query->whereHas('workflow', fn (Builder $workflow) => $workflow->where(function (Builder $scope): void {
                $scope->where('enterprise_specific', false)
                    ->orWhereIn('enterprise_id', static::authorizedEnterpriseIds());
            }));
        }

        $table = $model->getTable();
        $hasOrganization = Schema::hasColumn($table, 'organization_id');
        $hasEnterprise = Schema::hasColumn($table, 'enterprise_id');
        $hasUser = Schema::hasColumn($table, 'user_id');

        if ($hasOrganization) {
            $query->whereIn($table.'.organization_id', static::authorizedOrganizationIds());
        }

        if ($hasEnterprise) {
            $query->where(function (Builder $scope) use ($table, $hasOrganization): void {
                $scope->whereIn($table.'.enterprise_id', static::authorizedEnterpriseIds());

                if ($hasOrganization) {
                    $scope->orWhereNull($table.'.enterprise_id');
                }
            });
        }

        if ($hasUser) {
            $query->where($table.'.user_id', $user->getKey());
        }

        if (! $hasOrganization && ! $hasEnterprise && ! $hasUser) {
            return match (class_basename($model)) {
                'ReportSnapshot', 'ReportMetricValue' => $query->whereHas('report', fn (Builder $parent) => $parent->whereIn('enterprise_id', static::authorizedEnterpriseIds())
                ),
                'WorkflowStage' => $query->whereHas('workflow', fn (Builder $workflow) => $workflow->where(function (Builder $scope): void {
                    $scope->where('enterprise_specific', false)
                        ->orWhereIn('enterprise_id', static::authorizedEnterpriseIds());
                })
                ),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return $query;
    }

    protected static function canCreateForCurrentUser(string $model): bool
    {
        return auth()->check() && Gate::allows('create', $model);
    }
}