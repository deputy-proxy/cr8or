<?php

namespace App\Http\Controllers;

use App\Models\Enterprise;
use App\Services\Integrations\ProjectReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectReferenceController extends Controller
{
    public function index(Request $request, ProjectReferenceService $references): JsonResponse
    {
        $clientId = (string) $request->attributes->get('integration_oauth_client_id');

        return response()->json([
            'data' => $references->listForClient($clientId)
                ->map(fn (Enterprise $enterprise): array => $this->serialize($enterprise))
                ->all(),
        ]);
    }

    public function validate(Request $request, ProjectReferenceService $references): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $clientId = (string) $request->attributes->get('integration_oauth_client_id');
        $enterprises = $references->validateForClient($clientId, array_values(array_map('intval', $validated['ids'])));

        return response()->json([
            'data' => $enterprises
                ->map(fn (Enterprise $enterprise): array => $this->serialize($enterprise))
                ->all(),
        ]);
    }

    /** @return array{id: int, name: string, status: string} */
    private function serialize(Enterprise $enterprise): array
    {
        return [
            'id' => (int) $enterprise->getKey(),
            'name' => $enterprise->name,
            'status' => $enterprise->status,
        ];
    }
}
