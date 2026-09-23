<?php

namespace App\Services;

use App\Contracts\MediaGenerator;
use App\Contracts\MediaRenderer;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Execution;
use App\Models\GenerationJob;
use App\Models\GenerationRequest;
use App\Models\Job;
use App\Models\RenderJob;
use App\Models\RenderOutput;
use App\Models\RenderRequest;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final class MediaLifecycleService
{
    /** @param array<string,mixed> $parameters */
    public function requestGeneration(User $actor, Asset $asset, string $type, array $parameters, string $idempotencyKey): GenerationRequest
    {
        Gate::forUser($actor)->authorize('view', $asset);
        $request = GenerationRequest::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            ['enterprise_id' => $asset->enterprise_id, 'asset_id' => $asset->id, 'type' => $type, 'parameters' => $parameters, 'status' => GenerationRequest::STATUS_PENDING, 'correlation_id' => (string) Str::uuid()],
        );
        if ((int) $request->enterprise_id !== (int) $asset->enterprise_id) {
            throw new AuthorizationException('Generation request enterprise mismatch.');
        }
        if ($request->wasRecentlyCreated) {
            $this->createGenerationExecution($request);
        }

        return $request->refresh();
    }

    private function createGenerationExecution(GenerationRequest $request): void
    {
        $workflow = Workflow::query()->create(['enterprise_id' => $request->enterprise_id, 'name' => "media.generation:{$request->id}", 'status' => Workflow::STATUS_PENDING]);
        $job = Job::query()->create(['workflow_id' => $workflow->id, 'name' => 'media.generation', 'idempotency_key' => "media-generation:{$request->idempotency_key}", 'status' => Job::STATUS_PENDING]);
        $execution = Execution::query()->create(['workflow_job_id' => $job->id, 'status' => Execution::STATUS_PENDING]);
        GenerationJob::query()->create(['generation_request_id' => $request->id, 'workflow_job_id' => $job->id, 'execution_id' => $execution->id]);
    }

    public function startGeneration(GenerationRequest $request, MediaGenerator $generator): GenerationRequest
    {
        $request->transitionTo(GenerationRequest::STATUS_RUNNING)->save();
        /** @var GenerationJob $job */
        /** @var RenderJob $job */
        /** @var RenderJob $job */
        $job = $request->jobs()->latest('id')->firstOrFail();
        $job->workflowJob->start()->save();
        $job->status = 'running';
        $job->save();
        $job->execution->start()->save();
        try {
            $result = $generator->generate($request);
            $request->external_request_id = $result['external_id'] ?? null;
            $request->failure_reason = null;
            $request->failure_code = null;
            $request->transitionTo(GenerationRequest::STATUS_SUCCEEDED)->save();
            if (($result['asset_path'] ?? null) !== null) {
                /** @var Asset $asset */
                $asset = $request->asset;
                $this->registerVersion($asset, $result);
            }
            $job->external_job_id = $result['external_id'] ?? null;
            $job->status = Job::STATUS_SUCCEEDED;
            $job->save();
            $job->workflowJob->succeed()->save();
            $job->execution->succeed()->save();

            return $request->refresh();
        } catch (Throwable $e) {
            $request->fail($e->getMessage(), 'media.generation.failed')->save();
            $job->failure_reason = $e->getMessage();
            $job->status = Job::STATUS_FAILED;
            $job->save();
            if ($job->execution->status === Execution::STATUS_RUNNING) {
                $job->execution->fail($e->getMessage())->save();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $result */
    private function registerVersion(Asset $asset, array $result): AssetVersion
    {
        return AssetVersion::query()->create([
            'asset_id' => $asset->id,
            'version' => (int) $asset->versions()->max('version') + 1,
            'disk' => $result['disk'] ?? null,
            'path' => $result['asset_path'],
            'mime_type' => $result['mime_type'] ?? null,
            'size' => $result['size'] ?? null,
            'checksum' => $result['checksum'] ?? null,
            'metadata' => $result['metadata'] ?? null,
            'external_reference' => $result['external_id'] ?? null,
        ]);
    }

    public function retryGeneration(GenerationRequest $request): GenerationRequest
    {
        if ($request->status !== GenerationRequest::STATUS_FAILED) {
            throw new \LogicException('Only failed generation requests can be retried.');
        }
        $job = $request->jobs()->latest('id')->firstOrFail();
        $job->workflowJob->retry()->save();
        $job->status = 'pending';
        $job->failure_reason = null;
        $job->save();
        $request->failure_reason = null;
        $request->failure_code = null;
        $request->status = GenerationRequest::STATUS_PENDING;
        $request->save();

        return $request->refresh();
    }

    /** @param array<string,mixed> $parameters */
    public function requestRender(User $actor, Asset $asset, string $type, array $parameters, string $idempotencyKey, ?int $contentItemId = null): RenderRequest
    {
        Gate::forUser($actor)->authorize('view', $asset);
        /** @var AssetVersion|null $version */
        $version = $asset->versions()->orderByDesc('version')->first();
        $request = RenderRequest::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            ['enterprise_id' => $asset->enterprise_id, 'asset_id' => $asset->id, 'source_version_id' => $version?->id, 'content_item_id' => $contentItemId, 'type' => $type, 'parameters' => $parameters, 'status' => RenderRequest::STATUS_PENDING, 'correlation_id' => (string) Str::uuid()],
        );
        if ((int) $request->enterprise_id !== (int) $asset->enterprise_id) {
            throw new AuthorizationException('Render request enterprise mismatch.');
        }
        if ($request->wasRecentlyCreated) {
            $workflow = Workflow::query()->create(['enterprise_id' => $asset->enterprise_id, 'name' => "media.render:{$request->id}", 'status' => Workflow::STATUS_PENDING]);
            $job = Job::query()->create(['workflow_id' => $workflow->id, 'name' => 'media.render', 'idempotency_key' => "media-render:{$request->idempotency_key}", 'status' => Job::STATUS_PENDING]);
            $execution = Execution::query()->create(['workflow_job_id' => $job->id, 'status' => Execution::STATUS_PENDING]);
            RenderJob::query()->create(['render_request_id' => $request->id, 'workflow_job_id' => $job->id, 'execution_id' => $execution->id]);
        }

        return $request->refresh();
    }

    public function completeRender(RenderRequest $request, MediaRenderer $renderer): RenderOutput
    {
        $request->transitionTo(RenderRequest::STATUS_RUNNING)->save();
        $job = $request->jobs()->latest('id')->firstOrFail();
        $job->workflowJob->start()->save();
        $job->status = 'running';
        $job->save();
        $job->execution->start()->save();
        try {
            $result = $renderer->render($request);
            $request->external_request_id = $result['external_id'] ?? null;
            $request->transitionTo(RenderRequest::STATUS_SUCCEEDED)->save();
            /** @var Asset $asset */
            $asset = $request->asset;
            $version = $this->registerVersion($asset, $result);
            $job->external_job_id = $result['external_id'] ?? null;
            $job->status = Job::STATUS_SUCCEEDED;
            $job->save();
            $job->workflowJob->succeed()->save();
            $job->execution->succeed()->save();

            return RenderOutput::query()->create([
                'render_request_id' => $request->id, 'asset_version_id' => $version->id, 'external_output_id' => $result['external_id'] ?? null,
                'disk' => $result['disk'] ?? null, 'path' => $result['asset_path'] ?? null, 'mime_type' => $result['mime_type'] ?? null,
                'size' => $result['size'] ?? null, 'checksum' => $result['checksum'] ?? null, 'metadata' => $result['metadata'] ?? null,
            ]);
        } catch (Throwable $e) {
            $request->fail($e->getMessage(), 'media.render.failed')->save();
            $job->failure_reason = $e->getMessage();
            $job->status = Job::STATUS_FAILED;
            $job->save();
            if ($job->execution->status === Execution::STATUS_RUNNING) {
                $job->execution->fail($e->getMessage())->save();
            }
            throw $e;
        }
    }

    public function retryRender(RenderRequest $request): RenderRequest
    {
        if ($request->status !== RenderRequest::STATUS_FAILED) {
            throw new \LogicException('Only failed render requests can be retried.');
        }
        $job = $request->jobs()->latest('id')->firstOrFail();
        $job->workflowJob->retry()->save();
        $job->status = 'pending';
        $job->failure_reason = null;
        $job->save();
        $request->failure_reason = null;
        $request->failure_code = null;
        $request->status = RenderRequest::STATUS_PENDING;
        $request->save();

        return $request->refresh();
    }
}