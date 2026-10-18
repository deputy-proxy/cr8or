<?php

namespace App\Services\Integrations;

use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProjectReferenceService
{
    /** @return Collection<int, Enterprise> */
    public function listForClient(string $clientId): Collection
    {
        return Enterprise::query()
            ->whereIn('id', DB::table('integration_enterprise_access')
                ->select('enterprise_id')
                ->where('oauth_client_id', $clientId)
                ->where('active', true))
            ->orderBy('id')
            ->get(['id', 'name', 'status']);
    }

    /** @param list<int> $ids
     * @return Collection<int, Enterprise>
     */
    public function validateForClient(string $clientId, array $ids): Collection
    {
        $enterprises = $this->listForClient($clientId)
            ->keyBy(fn (Enterprise $enterprise): int => (int) $enterprise->getKey());

        foreach ($ids as $id) {
            if (! $enterprises->has($id)) {
                // Unknown and out-of-scope IDs intentionally have identical behavior.
                throw new NotFoundHttpException('One or more project references were not found.');
            }
        }

        return $enterprises->only($ids)->values();
    }
}
