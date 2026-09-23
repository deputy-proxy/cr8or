<?php

use App\Contracts\MediaGenerator;
use App\Contracts\MediaRenderer;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\Enterprise;
use App\Models\Execution;
use App\Models\GenerationRequest;
use App\Models\Job;
use App\Models\Membership;
use App\Models\RenderOutput;
use App\Models\RenderRequest;
use App\Models\User;
use App\Services\MediaLifecycleService;
use Illuminate\Support\Facades\Gate;
use LogicException;

function mediaActorFor(Enterprise $enterprise): User
{
    $actor = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor->id, 'organization_id' => $enterprise->organization_id]);

    return $actor;
}

it('creates correlated generation records', function () {
    $e = Enterprise::factory()->create();
    $a = Asset::factory()->create(['enterprise_id' => $e]);
    $u = mediaActorFor($e);
    $r = app(MediaLifecycleService::class)->requestGeneration($u, $a, 'image', ['prompt' => 'test'], 'gen-1');
    expect($r->status)->toBe(GenerationRequest::STATUS_PENDING)->and($r->jobs)->toHaveCount(1)->and($r->jobs->first()->execution->enterprise_id)->toBe($e->id);
});

it('is idempotent', function () {
    $e = Enterprise::factory()->create();
    $a = Asset::factory()->create(['enterprise_id' => $e]);
    $u = mediaActorFor($e);
    $s = app(MediaLifecycleService::class);
    $one = $s->requestGeneration($u, $a, 'image', [], 'same');
    $two = $s->requestGeneration($u, $a, 'image', ['other' => true], 'same');
    expect($two->id)->toBe($one->id)->and(GenerationRequest::query()->where('idempotency_key', 'same')->count())->toBe(1)->and($two->jobs)->toHaveCount(1);
});

it('preserves asset version history on successful generation', function () {
    $e = Enterprise::factory()->create();
    $a = Asset::factory()->create(['enterprise_id' => $e]);
    $u = mediaActorFor($e);
    $r = app(MediaLifecycleService::class)->requestGeneration($u, $a, 'image', [], 'gen-output');
    $generator = new class implements MediaGenerator
    {
        public function generate(GenerationRequest $r): array
        {
            return ['external_id' => 'provider-1', 'asset_path' => 'generated/1.png', 'mime_type' => 'image/png', 'metadata' => ['width' => 100]];
        }
    };
    app(MediaLifecycleService::class)->startGeneration($r, $generator);
    $v = $a->refresh()->versions()->first();
    expect($r->refresh()->status)->toBe(GenerationRequest::STATUS_SUCCEEDED)->and($v->version)->toBe(1)->and($v->path)->toBe('generated/1.png')->and($r->jobs->first()->workflowJob->status)->toBe(Job::STATUS_SUCCEEDED)->and($r->jobs->first()->execution->status)->toBe(Execution::STATUS_SUCCEEDED);
});

it('records failure and cannot turn it into success', function () {
    $e = Enterprise::factory()->create();
    $a = Asset::factory()->create(['enterprise_id' => $e]);
    $u = mediaActorFor($e);
    $r = app(MediaLifecycleService::class)->requestGeneration($u, $a, 'image', [], 'gen-fail');
    $generator = new class implements MediaGenerator
    {
        public function generate(GenerationRequest $r): array
        {
            throw new RuntimeException('provider unavailable');
        }
    };
    expect(fn () => app(MediaLifecycleService::class)->startGeneration($r, $generator))->toThrow(RuntimeException::class);
    expect($r->refresh()->status)->toBe(GenerationRequest::STATUS_FAILED)->and(fn () => $r->transitionTo(GenerationRequest::STATUS_SUCCEEDED))->toThrow(LogicException::class);
});

it('denies cross organization access', function () {
    $e = Enterprise::factory()->create();
    $foreign = Enterprise::factory()->create();
    $a = Asset::factory()->create(['enterprise_id' => $e]);
    $u = mediaActorFor($foreign);
    expect(Gate::forUser($u)->allows('view', $a))->toBeFalse();
});

it('correlates render output to request and new version', function () {
    $e = Enterprise::factory()->create();
    $a = Asset::factory()->create(['enterprise_id' => $e]);
    AssetVersion::factory()->create(['asset_id' => $a, 'version' => 1]);
    $u = mediaActorFor($e);
    $r = app(MediaLifecycleService::class)->requestRender($u, $a, 'video', [], 'render-1');
    $renderer = new class implements MediaRenderer
    {
        public function render(RenderRequest $r): array
        {
            return ['external_id' => 'render-1', 'asset_path' => 'renders/1.mp4', 'mime_type' => 'video/mp4'];
        }
    };
    $o = app(MediaLifecycleService::class)->completeRender($r, $renderer);
    expect($r->refresh()->status)->toBe(RenderRequest::STATUS_SUCCEEDED)->and($o)->toBeInstanceOf(RenderOutput::class)->and($o->request->id)->toBe($r->id)->and($o->assetVersion->version)->toBe(2);
});

it('does not create output on render failure', function () {
    $e = Enterprise::factory()->create();
    $a = Asset::factory()->create(['enterprise_id' => $e]);
    $u = mediaActorFor($e);
    $r = app(MediaLifecycleService::class)->requestRender($u, $a, 'video', [], 'render-fail');
    $renderer = new class implements MediaRenderer
    {
        public function render(RenderRequest $r): array
        {
            throw new RuntimeException('renderer unavailable');
        }
    };
    expect(fn () => app(MediaLifecycleService::class)->completeRender($r, $renderer))->toThrow(RuntimeException::class);
    expect($r->refresh()->status)->toBe(RenderRequest::STATUS_FAILED)->and(RenderOutput::query()->where('render_request_id',$r->id)->exists())->toBeFalse();
});