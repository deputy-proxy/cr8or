<?php

namespace App\Services\Integrations;

use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class IntegrationEnterpriseAccessService
{
    /**
     * Synchronize explicit Enterprise grants for a media OAuth client.
     *
     * @param  list<int|string>  $enterpriseIds
     */
    public function syncForConnection(User $actor, IntegrationConnection $connection, array $enterpriseIds): void
    {
        Gate::forUser($actor)->authorize('update', $connection);

        if ($connection->provider !== 'media') {
            throw ValidationException::withMessages(['connection' => 'Enterprise access can only be managed for media connections.']);
        }

        if (! is_string($connection->oauth_client_id) || $connection->oauth_client_id === '') {
            throw ValidationException::withMessages(['connection' => 'Associate an OAuth client before managing Enterprise access.']);
        }

        $ids = collect($enterpriseIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->contains(fn (int $id): bool => $id <= 0)) {
            throw ValidationException::withMessages(['enterprise_ids' => 'Select valid Enterprises.']);
        }

        $enterprises = Enterprise::query()
            ->where('organization_id', $connection->organization_id)
            ->whereIn('id', $ids)
            ->get();

        if ($enterprises->count() !== $ids->count()) {
            throw ValidationException::withMessages(['enterprise_ids' => 'One or more Enterprises are outside this connection’s organization.']);
        }

        foreach ($enterprises as $enterprise) {
            Gate::forUser($actor)->authorize('update', $enterprise);
        }

        $manageableEnterpriseIds = Enterprise::query()
            ->where('organization_id', $connection->organization_id)
            ->get()
            ->filter(fn (Enterprise $enterprise): bool => Gate::forUser($actor)->allows('update', $enterprise))
            ->modelKeys();

        DB::transaction(function () use ($connection, $ids, $manageableEnterpriseIds): void {
            if ($manageableEnterpriseIds !== []) {
                DB::table('integration_enterprise_access')
                    ->where('oauth_client_id', $connection->oauth_client_id)
                    ->whereIn('enterprise_id', $manageableEnterpriseIds)
                    ->where('active', true)
                    ->update(['active' => false, 'updated_at' => now()]);
            }

            foreach ($ids as $enterpriseId) {
                DB::table('integration_enterprise_access')->insertOrIgnore([
                    'oauth_client_id' => $connection->oauth_client_id,
                    'enterprise_id' => $enterpriseId,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('integration_enterprise_access')
                    ->where('oauth_client_id', $connection->oauth_client_id)
                    ->where('enterprise_id', $enterpriseId)
                    ->update(['active' => true, 'updated_at' => now()]);
            }
        });
    }
}