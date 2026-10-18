<?php

use App\Filament\Resources\Templates\TemplateResource;
use App\Filament\Resources\TemplateVersions\TemplateVersionResource;
use App\Models\Asset;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\MediaLifecycleService;
use App\Services\TemplateCatalogService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
});

function templateDefinition(string $name = 'Square social image', string $purpose = 'social'): array
{
    return [
        'catalog' => [
            'key' => 'social.square',
            'name' => $name,
            'description' => 'A square social asset.',
            'purpose' => $purpose,
            'output_format' => 'image',
            'renderer_targets' => ['still'],
            'schema_version' => '2',
            'input_contract' => ['required' => ['headline']],
            'media_contract' => ['accepted' => ['image']],
            'rendering_contract' => ['width' => 1080, 'height' => 1080],
        ],
    ];
}

function registerTemplateDefinition(TemplateCatalogService $service, array $definition, ?string $contents = null, ?string $revision = null): TemplateVersion
{
    $contents ??= json_encode($definition, JSON_THROW_ON_ERROR);

    return $service->registerDefinition($definition, 'deputy-proxy/cr8or-media', $revision ?? str_repeat('a', 40), 'data/templates/social-square.json', $contents);
}

it('registers a template idempotently and creates a new immutable version when the definition changes', function () {
    $service = app(TemplateCatalogService::class);
    $first = registerTemplateDefinition($service, templateDefinition());
    $same = registerTemplateDefinition($service, templateDefinition(), revision: str_repeat('b', 40));

    expect($same->id)->toBe($first->id)
        ->and(Template::query()->count())->toBe(1)
        ->and(TemplateVersion::query()->count())->toBe(1);

    $changed = registerTemplateDefinition($service, templateDefinition('Updated square social image'));

    expect($changed->id)->not->toBe($first->id)
        ->and($changed->version)->toBe(2)
        ->and($first->refresh()->status)->toBe(TemplateVersion::STATUS_DRAFT)
        ->and(TemplateVersion::query()->count())->toBe(2);
});

it('publishes only draft versions and preserves the exact historical version when a newer version is published', function () {
    $service = app(TemplateCatalogService::class);
    $firstDefinition = templateDefinition();
    $firstContents = json_encode($firstDefinition, JSON_THROW_ON_ERROR);
    $first = registerTemplateDefinition($service, $firstDefinition, $firstContents);
    $service->publish($first, $firstContents);
    $secondDefinition = templateDefinition('Updated square social image');
    $secondContents = json_encode($secondDefinition, JSON_THROW_ON_ERROR);
    $second = registerTemplateDefinition($service, $secondDefinition, $secondContents);
    expect($second->template->name)->toBe('Square social image');
    $service->publish($second, $secondContents);

    expect($service->publishedTemplates('image')->pluck('key')->all())->toBe(['social.square'])
        ->and($service->publishedTemplates('video'))->toBeEmpty()
        ->and($second->refresh()->status)->toBe(TemplateVersion::STATUS_PUBLISHED)
        ->and($first->refresh()->status)->toBe(TemplateVersion::STATUS_RETIRED)
        ->and($first->definition_sha256)->toBe(hash('sha256', $firstContents))
        ->and($second->template->name)->toBe('Updated square social image')
        ->and(Template::query()->where('key', 'social.square')->firstOrFail()->published_version_id)->toBe($second->id)
        ->and($service->resolvePublished('social.square')->id)->toBe($second->id)
        ->and(Template::query()->where('key', 'social.square')->value('name'))->toBe('Updated square social image');
});

it('prevents direct lifecycle status changes that bypass the catalogue service', function () {
    $service = app(TemplateCatalogService::class);
    $version = registerTemplateDefinition($service, templateDefinition());

    expect(function () use ($version): void {
        $version->status = TemplateVersion::STATUS_PUBLISHED;
        $version->save();
    })->toThrow(LogicException::class, 'identity is immutable');

    expect($version->refresh()->status)->toBe(TemplateVersion::STATUS_DRAFT);
});

it('prevents published definition identity and contract fields from being changed', function () {
    $service = app(TemplateCatalogService::class);
    $definition = templateDefinition();
    $contents = json_encode($definition, JSON_THROW_ON_ERROR);
    $version = registerTemplateDefinition($service, $definition, $contents);
    $service->publish($version, $contents);

    expect(fn () => $version->refresh()->forceFill(['definition_sha256' => str_repeat('b', 64)])->save())
        ->toThrow(LogicException::class, 'TemplateVersion identity is immutable; register a new version instead of editing it.');
});

it('rejects invalid or unsafe definition metadata without creating catalogue records', function () {
    $service = app(TemplateCatalogService::class);

    expect(fn () => $service->registerDefinition(
        ['catalog' => ['key' => 'bad key', 'name' => 'Invalid', 'output_format' => 'image', 'schema_version' => '1']],
        'deputy-proxy/cr8or-media',
        str_repeat('a', 40),
        'templates/bad.json',
        '{"invalid":true}',
    ))->toThrow(RuntimeException::class);

    expect(Template::query()->count())->toBe(0)
        ->and(TemplateVersion::query()->count())->toBe(0);

    expect(fn () => $service->registerDefinition(
        ['catalog' => ['key' => 'social.square', 'name' => 'Unsupported', 'output_format' => 'image', 'schema_version' => '3']],
        'deputy-proxy/cr8or-media',
        str_repeat('a', 40),
        'data/templates/social-square.json',
        json_encode(['catalog' => ['key' => 'social.square', 'name' => 'Unsupported', 'output_format' => 'image', 'schema_version' => '3']], JSON_THROW_ON_ERROR),
    ))->toThrow(RuntimeException::class, 'unsupported template schema version');
});

it('stores the resolved published template version on render requests', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $asset = Asset::factory()->create(['enterprise_id' => $enterprise]);
    $service = app(TemplateCatalogService::class);
    $definition = templateDefinition();
    $contents = json_encode($definition, JSON_THROW_ON_ERROR);
    $version = registerTemplateDefinition($service, $definition, $contents);
    $service->publish($version, $contents);

    $request = app(MediaLifecycleService::class)->requestRender(
        $actor,
        $asset,
        'image',
        ['template_key' => 'social.square', 'template_inputs' => ['headline' => 'Hello']],
        'render-template-version-test',
    );

    expect($request->template_version_id)->toBe($version->id)
        ->and($request->templateVersion->definition_sha256)->toBe($version->definition_sha256);

    expect(fn () => $request->forceFill(['template_version_id' => null])->save())
        ->toThrow(LogicException::class, 'template version identity is immutable');
});

it('fails closed when the published definition artifact is missing or corrupt', function () {
    $service = app(TemplateCatalogService::class);
    $definition = templateDefinition();
    $contents = json_encode($definition, JSON_THROW_ON_ERROR);
    $version = registerTemplateDefinition($service, $definition, $contents);
    $service->publish($version, $contents);

    Storage::disk('r2')->delete($version->definition_artifact_path);

    expect(fn () => $service->resolvePublished('social.square'))
        ->toThrow(RuntimeException::class, 'artifact is unavailable');

    Storage::disk('r2')->put($version->definition_artifact_path, '{"tampered":true}');
    expect(fn () => $service->resolvePublished('social.square'))
        ->toThrow(RuntimeException::class, 'integrity check failed');
});

it('exposes platform templates only to authenticated organization members and keeps generic CRUD disabled', function () {
    $service = app(TemplateCatalogService::class);
    registerTemplateDefinition($service, templateDefinition());
    $member = User::factory()->create();
    $organization = Organization::factory()->create();
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);
    $outsider = User::factory()->create();

    $this->actingAs($member);
    expect(TemplateResource::getEloquentQuery()->pluck('key')->all())->toContain('social.square')
        ->and(TemplateVersionResource::getEloquentQuery()->pluck('template_id')->all())->toContain(Template::query()->where('key', 'social.square')->value('id'))
        ->and(Gate::forUser($member)->allows('view', Template::query()->firstOrFail()))->toBeTrue()
        ->and(TemplateResource::canCreate())->toBeFalse();

    $this->actingAs($outsider);
    expect(TemplateResource::getEloquentQuery()->pluck('key')->all())->toBeEmpty()
        ->and(TemplateVersionResource::getEloquentQuery()->pluck('id')->all())->toBeEmpty()
        ->and(Gate::forUser($outsider)->allows('viewAny', Template::class))->toBeFalse();
});

it('scopes organization- and enterprise-owned templates to authorized memberships', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $outsider, 'organization_id' => $otherOrganization]);

    Template::query()->create([
        'key' => 'organization.custom',
        'name' => 'Organization template',
        'output_format' => 'image',
        'ownership_scope' => Template::SCOPE_ORGANIZATION,
        'organization_id' => $organization->id,
        'status' => Template::STATUS_DRAFT,
    ]);
    Template::query()->create([
        'key' => 'enterprise.custom',
        'name' => 'Enterprise template',
        'output_format' => 'video',
        'ownership_scope' => Template::SCOPE_ENTERPRISE,
        'enterprise_id' => $enterprise->id,
        'status' => Template::STATUS_DRAFT,
    ]);

    $this->actingAs($member);
    expect(TemplateResource::getEloquentQuery()->pluck('key')->all())
        ->toContain('organization.custom', 'enterprise.custom');

    $this->actingAs($outsider);
    expect(TemplateResource::getEloquentQuery()->pluck('key')->all())->toBeEmpty();
});

it('validates the whole registration batch before persisting any definitions', function () {
    $checkout = storage_path('framework/testing/cr8or-media-invalid-checkout');
    $directory = $checkout.'/data/templates';
    if (is_dir($checkout)) {
        \Illuminate\Support\Facades\File::deleteDirectory($checkout);
    }
    mkdir($directory, 0777, true);

    file_put_contents($directory.'/01-valid.json', json_encode(templateDefinition(), JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/02-invalid.json', '{ invalid json');

    foreach ([
        ['git', 'init', '-q', $checkout],
        ['git', '-C', $checkout, 'config', 'user.email', 'template-test@example.invalid'],
        ['git', '-C', $checkout, 'config', 'user.name', 'Template Test'],
        ['git', '-C', $checkout, 'remote', 'add', 'origin', 'https://github.com/deputy-proxy/cr8or-media.git'],
        ['git', '-C', $checkout, 'add', 'data/templates'],
        ['git', '-C', $checkout, 'commit', '-q', '-m', 'template test fixture'],
    ] as $command) {
        $process = new \Symfony\Component\Process\Process($command);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    try {
        $this->artisan('templates:register', [
            'path' => $directory,
        ])->assertExitCode(1);

        expect(Template::query()->count())->toBe(0)
            ->and(TemplateVersion::query()->count())->toBe(0);
    } finally {
        \Illuminate\Support\Facades\File::deleteDirectory($checkout);
    }
});

it('rejects duplicate stable keys in a registration batch without partial catalogue writes', function () {
    $checkout = storage_path('framework/testing/cr8or-media-duplicate-checkout');
    $directory = $checkout.'/data/templates';
    if (is_dir($checkout)) {
        \Illuminate\Support\Facades\File::deleteDirectory($checkout);
    }
    mkdir($directory, 0777, true);
    file_put_contents($directory.'/01-first.json', json_encode(templateDefinition('First'), JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/02-second.json', json_encode(templateDefinition('Second'), JSON_THROW_ON_ERROR));

    foreach ([
        ['git', 'init', '-q', $checkout],
        ['git', '-C', $checkout, 'config', 'user.email', 'template-test@example.invalid'],
        ['git', '-C', $checkout, 'config', 'user.name', 'Template Test'],
        ['git', '-C', $checkout, 'remote', 'add', 'origin', 'https://github.com/deputy-proxy/cr8or-media.git'],
        ['git', '-C', $checkout, 'add', 'data/templates'],
        ['git', '-C', $checkout, 'commit', '-q', '-m', 'duplicate template fixture'],
    ] as $command) {
        $process = new \Symfony\Component\Process\Process($command);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    try {
        $this->artisan('templates:register', [
            'path' => $directory,
        ])->assertExitCode(1);

        expect(Template::query()->count())->toBe(0)
            ->and(TemplateVersion::query()->count())->toBe(0);
    } finally {
        \Illuminate\Support\Facades\File::deleteDirectory($checkout);
    }
});

it('adapts the current cr8or-media v2 image and reel definition shapes', function () {
    $service = app(TemplateCatalogService::class);
    $image = [
        'version' => 2,
        'id' => 'myth-reality-image',
        'name' => 'Myth vs Reality',
        'project' => 'EdVenture',
        'format' => 'image',
        'canvas' => ['width' => 1080, 'height' => 1350, 'fps' => 30, 'duration' => 1],
        'sampleData' => ['title' => 'A title'],
        'background' => ['type' => 'lightfall', 'props' => []],
        'elements' => [['id' => 'title', 'type' => 'text', 'text' => '{{title}}']],
    ];
    $video = [...$image, 'id' => 'myth-reality-video', 'name' => 'Myth vs Reality · Video', 'format' => 'reel', 'canvas' => ['width' => 1080, 'height' => 1920, 'fps' => 30, 'duration' => 10]];

    $imageVersion = $service->registerDefinition($image, 'deputy-proxy/cr8or-media', str_repeat('a', 40), 'data/templates/myth-reality-image.json', json_encode($image, JSON_THROW_ON_ERROR));
    $videoVersion = $service->registerDefinition($video, 'deputy-proxy/cr8or-media', str_repeat('a', 40), 'data/templates/myth-reality-video.json', json_encode($video, JSON_THROW_ON_ERROR));

    expect($imageVersion->template->key)->toBe('myth-reality-image')
        ->and($imageVersion->template->project)->toBe('EdVenture')
        ->and($imageVersion->template->output_format)->toBe('image')
        ->and($imageVersion->template->renderer_targets)->toBe(['cr8or-image'])
        ->and($imageVersion->input_contract['optional'])->toBe(['title'])
        ->and($imageVersion->rendering_contract['canvas']['height'])->toBe(1350)
        ->and($videoVersion->template->key)->toBe('myth-reality-video')
        ->and($videoVersion->template->output_format)->toBe('video')
        ->and($videoVersion->template->renderer_targets)->toBe(['cr8or-video']);
});

it('validates template inputs before creating a generation request and retains the selected version', function () {
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $asset = Asset::factory()->create(['enterprise_id' => $enterprise]);
    $catalog = app(TemplateCatalogService::class);
    $definition = templateDefinition();
    $contents = json_encode($definition, JSON_THROW_ON_ERROR);
    $version = registerTemplateDefinition($catalog, $definition, $contents);
    $catalog->publish($version, $contents);

    expect(fn () => app(MediaLifecycleService::class)->requestGeneration(
        $actor,
        $asset,
        'image',
        ['template_key' => 'social.square', 'template_inputs' => []],
        'generation-template-version-missing-input',
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(\App\Models\GenerationRequest::query()->count())->toBe(0);

    $request = app(MediaLifecycleService::class)->requestGeneration(
        $actor,
        $asset,
        'image',
        ['template_key' => 'social.square', 'template_inputs' => ['headline' => 'Hello']],
        'generation-template-version-valid',
    );

    expect($request->template_version_id)->toBe($version->id)
        ->and($request->templateVersion->definition_sha256)->toBe($version->definition_sha256);

    expect(fn () => $request->forceFill(['template_version_id' => null])->save())
        ->toThrow(LogicException::class, 'template version identity is immutable');
});

it('registers and publishes only a clean, pinned definition from the canonical source directory', function () {
    $checkout = storage_path('framework/testing/cr8or-media-publish-checkout');
    $directory = $checkout.'/data/templates';
    if (is_dir($checkout)) {
        \Illuminate\Support\Facades\File::deleteDirectory($checkout);
    }
    mkdir($directory, 0777, true);
    $definition = [
        'version' => 2,
        'id' => 'myth-reality-image',
        'name' => 'Myth vs Reality',
        'project' => 'EdVenture',
        'format' => 'image',
        'canvas' => ['width' => 1080, 'height' => 1350, 'fps' => 30, 'duration' => 1],
        'sampleData' => ['title' => 'A title'],
        'background' => ['type' => 'lightfall', 'props' => []],
        'elements' => [['id' => 'title', 'type' => 'text', 'name' => 'Title', 'text' => '{{title}}']],
    ];
    file_put_contents($directory.'/myth-reality-image.json', json_encode($definition, JSON_THROW_ON_ERROR));

    foreach ([
        ['git', 'init', '-q', $checkout],
        ['git', '-C', $checkout, 'config', 'user.email', 'template-test@example.invalid'],
        ['git', '-C', $checkout, 'config', 'user.name', 'Template Test'],
        ['git', '-C', $checkout, 'remote', 'add', 'origin', 'https://github.com/deputy-proxy/cr8or-media.git'],
        ['git', '-C', $checkout, 'add', 'data/templates'],
        ['git', '-C', $checkout, 'commit', '-q', '-m', 'template test fixture'],
    ] as $command) {
        $process = new \Symfony\Component\Process\Process($command);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    try {
        $this->artisan('templates:register', [
            'path' => $directory,
        ])->assertExitCode(0);

        $version = TemplateVersion::query()->firstOrFail();
        expect($version->status)->toBe(TemplateVersion::STATUS_DRAFT)
            ->and($version->source_revision)->toBe(trim((new \Symfony\Component\Process\Process(['git', '-C', $checkout, 'rev-parse', 'HEAD']))->mustRun()->getOutput()));

        $this->artisan('templates:publish', [
            'version' => $version->getKey(),
            'source-checkout' => $checkout,
        ])->assertExitCode(0);

        expect($version->refresh()->status)->toBe(TemplateVersion::STATUS_PUBLISHED)
            ->and(Template::query()->where('key', 'myth-reality-image')->value('published_version_id'))->toBe($version->id);
    } finally {
        \Illuminate\Support\Facades\File::deleteDirectory($checkout);
    }
});