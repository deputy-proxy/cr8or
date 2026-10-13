<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Publication;
use App\Models\User;
use App\Services\PublishingService;
use Illuminate\Support\Facades\Gate;

final class ReconcilePublicationStatus implements Operation
{
    public function __construct(private readonly PublishingService $publishing) {}

    /** @return array<string, mixed> */
    public function execute(User $actor, array $input): array
    {
        $publication = ($input['publication'] ?? null) instanceof Publication
            ? $input['publication']
            : Publication::query()->with('enterprise')->findOrFail((int) ($input['publication_id'] ?? 0));

        Gate::forUser($actor)->authorize('reconcileStatus', $publication);

        $reconciliationStatus = $this->publishing->reconcileStatus($publication);
        $publication->refresh();
        $latestResult = $publication->results()->latest('id')->first();

        return [
            'publication_id' => (int) $publication->id,
            'reconciliation_status' => $reconciliationStatus,
            'status' => $publication->status,
            'external_id' => $publication->external_id,
            'external_url' => $publication->external_url,
            'failure_code' => $publication->failure_code,
            'failure_reason' => $publication->failure_reason,
            'latest_result_id' => $latestResult?->id,
        ];
    }
}
