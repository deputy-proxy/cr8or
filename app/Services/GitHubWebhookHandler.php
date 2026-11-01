<?php

namespace App\Services;

use App\Jobs\DiscoverGitHubRepositories;
use App\Jobs\RefreshGitHubIssueSnapshot;
use App\Jobs\SyncIntegrationRepositoryIssues;
use App\Models\GitHubWebhookDelivery;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class GitHubWebhookHandler
{
    public function handle(GitHubWebhookDelivery $delivery): void
    {
        $connection = $delivery->connection;
        if ($connection === null || (int) $connection->github_installation_id !== (int) $delivery->installation_id) {
            $delivery->forceFill(['status' => 'ignored', 'failure_reason' => 'Installation no longer matches an active integration connection.', 'processed_at' => now()])->save();

            return;
        }

        $delivery->forceFill(['status' => 'processing'])->save();
        if ($delivery->event_type !== 'issues') {
            Cache::forget('github-app-installation-token:'.hash('sha256', (string) $connection->github_installation_id));
        }
        try {
            match ($delivery->event_type) {
                'installation' => $this->handleInstallation($delivery, $connection),
                'installation_repositories' => $this->handleRepositoryAccess($delivery, $connection),
                'issues' => $this->handleIssueChange($delivery, $connection),
                default => null,
            };
            $delivery->forceFill(['status' => 'processed', 'failure_reason' => null, 'processed_at' => now()])->save();
        } catch (Throwable $exception) {
            $delivery->forceFill(['status' => 'failed', 'failure_reason' => mb_substr($exception->getMessage(), 0, 500)])->save();
            throw $exception;
        }
    }

    private function handleInstallation(GitHubWebhookDelivery $delivery, IntegrationConnection $connection): void
    {
        $payload = $delivery->getAttribute('payload');
        $payload = is_array($payload) ? $payload : [];
        $account = $payload['account'] ?? [];
        $metadata = $connection->getAttribute('metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        $wasSuspended = ($metadata['installation_suspended_at'] ?? null) !== null;
        if (is_array($account)) {
            if (is_numeric($account['id'] ?? null)) {
                $metadata['github_account_id'] = (int) $account['id'];
            }
            if (is_string($account['login'] ?? null)) {
                $metadata['github_account_login'] = mb_substr($account['login'], 0, 255);
            }
            if (is_string($account['type'] ?? null)) {
                $metadata['github_account_type'] = mb_substr($account['type'], 0, 50);
            }
        }
        $action = $delivery->action;
        if ($action === 'suspend') {
            if (! $wasSuspended) {
                $metadata['status_before_installation_suspension'] = $connection->status;
            }
            $metadata['installation_suspended_at'] = now()->toIso8601String();
            if (in_array($connection->status, [IntegrationConnection::STATUS_ACTIVE, IntegrationConnection::STATUS_DEGRADED], true)) {
                $connection->transitionTo(IntegrationConnection::STATUS_DISABLED);
            }
        } elseif ($action === 'unsuspend') {
            $metadata['installation_suspended_at'] = null;
            $statusBeforeSuspension = $metadata['status_before_installation_suspension'] ?? null;
            unset($metadata['status_before_installation_suspension']);
            if ($wasSuspended && $connection->status === IntegrationConnection::STATUS_DISABLED
                && in_array($statusBeforeSuspension, [IntegrationConnection::STATUS_ACTIVE, IntegrationConnection::STATUS_DEGRADED], true)) {
                $connection->transitionTo($statusBeforeSuspension);
            }
        } elseif ($action === 'deleted') {
            $metadata['installation_deleted_at'] = now()->toIso8601String();
            if ($connection->status !== IntegrationConnection::STATUS_REVOKED) {
                $connection->transitionTo(IntegrationConnection::STATUS_REVOKED);
            }
            $connection->repositories()->update(['status' => IntegrationRepository::STATUS_INACCESSIBLE]);
        } elseif ($action === 'created') {
            $metadata['installation_created_at'] = now()->toIso8601String();
        }
        $permissions = $payload['permissions'] ?? [];
        if (is_array($permissions) && $permissions !== []) {
            $metadata['github_permissions'] = $permissions;
            if (($permissions['issues'] ?? null) !== 'read'
                && in_array($connection->status, [IntegrationConnection::STATUS_ACTIVE, IntegrationConnection::STATUS_DEGRADED, IntegrationConnection::STATUS_DISABLED], true)) {
                $connection->transitionTo(IntegrationConnection::STATUS_RECONNECT_REQUIRED);
            } elseif (($permissions['issues'] ?? null) === 'read'
                && $connection->status === IntegrationConnection::STATUS_RECONNECT_REQUIRED
                && ($metadata['installation_suspended_at'] ?? null) === null
                && ($metadata['installation_deleted_at'] ?? null) === null) {
                $connection->reauthorize('github-app-installation');
            }
        }
        $connection->forceFill(['metadata' => $metadata])->save();
    }

    private function handleRepositoryAccess(GitHubWebhookDelivery $delivery, IntegrationConnection $connection): void
    {
        foreach (['repositories_removed' => IntegrationRepository::STATUS_INACCESSIBLE, 'repositories_added' => IntegrationRepository::STATUS_ACTIVE] as $key => $status) {
            $payload = $delivery->getAttribute('payload');
            $payload = is_array($payload) ? $payload : [];
            $repositories = $payload[$key] ?? [];
            if (! is_array($repositories)) {
                continue;
            }
            foreach ($repositories as $repository) {
                $id = is_array($repository) ? ($repository['id'] ?? null) : null;
                if (! is_numeric($id)) {
                    continue;
                }
                $record = $connection->repositories()->where('github_repository_id', (int) $id)->first();
                if ($record !== null) {
                    $record->forceFill(['status' => $status])->save();
                }
            }
        }
        if ($delivery->action === 'added') {
            DiscoverGitHubRepositories::dispatch((int) $connection->id)->afterCommit();
        }
    }

    private function handleIssueChange(GitHubWebhookDelivery $delivery, IntegrationConnection $connection): void
    {
        $repository = $connection->repositories()
            ->where('github_repository_id', $delivery->github_repository_id)
            ->where('status', IntegrationRepository::STATUS_ACTIVE)
            ->first();
        if ($repository === null || $repository->enterprise_id === null) {
            return;
        }
        $payload = $delivery->getAttribute('payload');
        $payload = is_array($payload) ? $payload : [];
        if ($delivery->action === 'deleted' && is_numeric($payload['issue_number'] ?? null)) {
            RefreshGitHubIssueSnapshot::dispatch((int) $repository->id, (int) $payload['issue_number'])->afterCommit();

            return;
        }
        SyncIntegrationRepositoryIssues::dispatch((int) $repository->id)->afterCommit();
    }
}
