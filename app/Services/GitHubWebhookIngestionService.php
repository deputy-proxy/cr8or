<?php

namespace App\Services;

use App\Jobs\ProcessGitHubWebhookDelivery;
use App\Models\GitHubWebhookDelivery;
use App\Models\IntegrationConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class GitHubWebhookIngestionService
{
    public function __construct(private readonly GitHubWebhookVerifier $verifier) {}

    public function ingest(string $rawBody, ?string $signature, ?string $deliveryId, ?string $eventType): GitHubWebhookDelivery
    {
        if (strlen($rawBody) > 1_048_576) {
            throw new RuntimeException('GitHub webhook payload exceeds the allowed size.');
        }
        if (! $this->verifier->verify($rawBody, $signature)) {
            throw new RuntimeException('GitHub webhook signature verification failed.');
        }
        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('GitHub webhook payload must be a JSON object.');
        }
        if (! is_string($deliveryId) || ! preg_match('/^[A-Za-z0-9-]{1,128}$/', $deliveryId)
            || ! is_string($eventType) || ! in_array($eventType, ['ping', 'issues', 'installation', 'installation_repositories'], true)) {
            throw new RuntimeException('GitHub webhook delivery headers are invalid or unsupported.');
        }

        $installationId = null;
        $repositoryId = null;
        $action = null;
        $connection = null;
        $repository = null;
        $deliveryStatus = 'processed';
        $failureReason = null;
        $processedAt = now();
        $sanitizedPayload = [];

        if ($eventType !== 'ping') {
            $allowedActions = match ($eventType) {
                'installation' => ['created', 'deleted', 'suspend', 'unsuspend', 'new_permissions_accepted'],
                'installation_repositories' => ['added', 'removed'],
                'issues' => ['opened', 'edited', 'deleted', 'transferred', 'pinned', 'unpinned', 'closed', 'reopened', 'assigned', 'unassigned', 'labeled', 'unlabeled', 'milestoned', 'demilestoned', 'locked', 'unlocked'],
            };
            $action = data_get($payload, 'action');
            if (! in_array($action, $allowedActions, true)) {
                throw new RuntimeException('GitHub webhook action is unsupported.');
            }
            $installationId = data_get($payload, 'installation.id');
            if (! (is_int($installationId) || (is_string($installationId) && ctype_digit($installationId))) || (int) $installationId < 1) {
                throw new RuntimeException('GitHub webhook installation identity is required.');
            }
            $repositoryId = data_get($payload, 'repository.id');
            if ($eventType === 'issues' && ! $this->isPositiveInteger($repositoryId)) {
                throw new RuntimeException('GitHub issue webhook repository identity is required.');
            }
            $connection = IntegrationConnection::query()->where('provider', 'github')->where('github_installation_id', (int) $installationId)->first();
            $repository = $connection !== null && $this->isPositiveInteger($repositoryId)
                ? $connection->repositories()->where('github_repository_id', (int) $repositoryId)->first()
                : null;
            $deliveryStatus = $connection === null ? 'ignored' : 'received';
            $failureReason = $connection === null ? 'Unknown installation.' : null;
            $processedAt = null;
            $sanitizedPayload = $this->sanitizePayload($eventType, $payload);
        }

        try {
            $delivery = DB::transaction(fn () => GitHubWebhookDelivery::query()->create([
                'delivery_id' => $deliveryId,
                'organization_id' => $connection?->organization_id,
                'enterprise_id' => $repository?->enterprise_id,
                'event_type' => $eventType,
                'action' => is_string($action) ? Str::limit($action, 100, '') : null,
                'installation_id' => is_numeric($installationId) ? (int) $installationId : null,
                'github_repository_id' => $this->isPositiveInteger($repositoryId) ? (int) $repositoryId : null,
                'integration_connection_id' => $connection?->id,
                'payload_hash' => hash('sha256', $rawBody),
                'payload' => $sanitizedPayload,
                'status' => $deliveryStatus,
                'failure_reason' => $failureReason,
                'received_at' => now(),
                'processed_at' => $processedAt,
            ]));
        } catch (QueryException $exception) {
            if (GitHubWebhookDelivery::query()->where('delivery_id', $deliveryId)->exists()) {
                return GitHubWebhookDelivery::query()->where('delivery_id', $deliveryId)->firstOrFail();
            }
            throw $exception;
        }

        if ($connection !== null) {
            ProcessGitHubWebhookDelivery::dispatch((int) $delivery->id)->afterCommit();
        }

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(string $eventType, array $payload): array
    {
        if ($eventType === 'installation') {
            $account = data_get($payload, 'installation.account', []);
            $account = is_array($account) ? $account : [];

            return ['account' => [
                'id' => $this->isPositiveInteger($account['id'] ?? null) ? (int) $account['id'] : null,
                'login' => is_string($account['login'] ?? null) ? mb_substr($account['login'], 0, 255) : null,
                'type' => is_string($account['type'] ?? null) ? mb_substr($account['type'], 0, 50) : null,
            ], 'permissions' => $this->sanitizePermissions(data_get($payload, 'installation.permissions', []))];
        }
        if ($eventType === 'installation_repositories') {
            $result = [];
            foreach (['repositories_added', 'repositories_removed'] as $key) {
                $repositories = data_get($payload, $key, []);
                $sanitized = [];
                foreach (is_array($repositories) ? $repositories : [] as $repository) {
                    if (is_array($repository) && $this->isPositiveInteger($repository['id'] ?? null)) {
                        $sanitized[] = ['id' => (int) $repository['id']];
                    }
                    if (count($sanitized) >= 100) {
                        break;
                    }
                }
                $result[$key] = $sanitized;
            }

            return $result;
        }
        if ($eventType === 'issues') {
            $issue = data_get($payload, 'issue', []);
            $issue = is_array($issue) ? $issue : [];

            return ['issue_number' => $this->isPositiveInteger($issue['number'] ?? null) ? (int) $issue['number'] : null];
        }

        return [];
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && ctype_digit($value) && (int) $value > 0);
    }

    /** @return array<string, string> */
    private function sanitizePermissions(mixed $permissions): array
    {
        if (! is_array($permissions)) {
            return [];
        }
        $result = [];
        foreach (['issues', 'metadata', 'contents', 'projects'] as $key) {
            if (is_string($permissions[$key] ?? null)) {
                $result[$key] = mb_substr($permissions[$key], 0, 20);
            }
        }

        return $result;
    }
}
