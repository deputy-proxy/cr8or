<?php

namespace App\Http\Controllers;

use App\Services\GitHubWebhookIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, GitHubWebhookIngestionService $ingestion): JsonResponse
    {
        try {
            $delivery = $ingestion->ingest(
                $request->getContent(),
                $request->header('X-Hub-Signature-256'),
                $request->header('X-GitHub-Delivery'),
                $request->header('X-GitHub-Event'),
            );
        } catch (RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'signature verification') ? 401 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json(['delivery_id' => $delivery->delivery_id, 'status' => $delivery->status], 202);
    }
}
