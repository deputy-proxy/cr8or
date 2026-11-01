<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\IntegrationRepository;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class IntegrationRepositoryAssignmentService
{
    public function assign(User $actor, IntegrationRepository $repository, Enterprise $enterprise): IntegrationRepository
    {
        Gate::forUser($actor)->authorize('update', $enterprise);
        Gate::forUser($actor)->authorize('update', $repository->connection);

        if (($repository->connection->enterprise_id !== null && (int) $repository->connection->enterprise_id !== (int) $enterprise->id)
            || (int) $repository->organization_id !== (int) $enterprise->organization_id
            || (int) $repository->connection->organization_id !== (int) $enterprise->organization_id) {
            throw ValidationException::withMessages(['enterprise_id' => 'The repository and Enterprise must belong to the same organization.']);
        }
        if ($repository->status !== IntegrationRepository::STATUS_ACTIVE || ! $repository->connection->isOperational()) {
            throw ValidationException::withMessages(['repository' => 'Only repositories accessible through an active GitHub installation can be assigned.']);
        }

        DB::transaction(function () use ($actor, $repository, $enterprise): void {
            $previousEnterpriseIds = $repository->issues()->whereNotNull('enterprise_id')->distinct()->pluck('enterprise_id');
            foreach ($previousEnterpriseIds as $previousEnterpriseId) {
                if ((int) $previousEnterpriseId === (int) $enterprise->id) {
                    continue;
                }
                $previousEnterprise = Enterprise::query()->findOrFail($previousEnterpriseId);
                Gate::forUser($actor)->authorize('update', $previousEnterprise);
            }

            $repository->enterprise()->associate($enterprise);
            $repository->save();
            $repository->issues()->update([
                'enterprise_id' => $enterprise->id,
                'repository' => $repository->full_name,
                'github_repository_id' => $repository->github_repository_id,
            ]);
        });

        return $repository->refresh();
    }

    public function unassign(User $actor, IntegrationRepository $repository): IntegrationRepository
    {
        Gate::forUser($actor)->authorize('update', $repository->connection);
        if ($repository->enterprise !== null) {
            Gate::forUser($actor)->authorize('update', $repository->enterprise);
        }
        $enterprise = $repository->enterprise;
        $repository->enterprise()->dissociate();
        $repository->save();

        if ($enterprise !== null && strcasecmp((string) $enterprise->github_repository, $repository->full_name) === 0) {
            $enterprise->forceFill(['github_repository' => null, 'github_repository_url' => null])->save();
        }

        return $repository->refresh();
    }
}
