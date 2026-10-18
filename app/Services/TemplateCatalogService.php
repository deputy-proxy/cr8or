<?php

namespace App\Services;

use App\Contracts\MediaStorage;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

final class TemplateCatalogService
{
    public function __construct(private readonly TemplateDefinitionAdapter $definitionAdapter, private readonly MediaStorage $mediaStorage) {}

    /** @param array<string, mixed> $definition */
    public function registerDefinition(array $definition, string $sourceRepository, string $sourceRevision, string $definitionPath, string $definitionContents, ?User $actor = null): TemplateVersion
    {
        $decoded = json_decode($definitionContents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || $decoded !== $definition) {
            throw new RuntimeException('The decoded definition must match the exact JSON contents being registered.');
        }
        $metadata = $this->definitionAdapter->normalize($definition, $definitionPath);
        $key = (string) $metadata['key'];
        $name = (string) $metadata['name'];
        $format = (string) $metadata['output_format'];
        $schemaVersion = (string) $metadata['schema_version'];
        if (! preg_match('/^[a-f0-9]{40,64}$/i', $sourceRevision)) {
            throw new RuntimeException('Template source revision must be a full Git commit SHA.');
        }
        if (str_contains($definitionPath, '..') || str_starts_with($definitionPath, '/')) {
            throw new RuntimeException('Template definition path must be relative and cannot traverse directories.');
        }
        if ($sourceRepository === 'deputy-proxy/cr8or-media' && ! str_starts_with($definitionPath, 'data/templates/')) {
            throw new RuntimeException('Built-in cr8or-media definitions must come from the canonical data/templates directory.');
        }
        $hash = hash('sha256', $definitionContents);
        $artifactPath = 'template-definitions/'.$key.'/'.$hash.'.json';
        $disk = Storage::disk('r2');
        if ($disk->exists($artifactPath)) {
            $existingContents = $disk->get($artifactPath);
            if (! is_string($existingContents) || ! hash_equals($hash, hash('sha256', $existingContents))) {
                throw new RuntimeException('An existing R2 template definition artifact failed its integrity check.');
            }
        } else {
            $this->mediaStorage->store($artifactPath, $definitionContents, ['sha256' => $hash]);
        }

        return DB::transaction(function () use ($key, $name, $format, $schemaVersion, $metadata, $sourceRepository, $sourceRevision, $definitionPath, $hash, $artifactPath, $actor): TemplateVersion {
            $template = Template::query()->where('key', $key)->lockForUpdate()->first();
            if ($template === null) {
                $template = Template::query()->create([
                    'key' => $key,
                    'name' => $name,
                    'description' => isset($metadata['description']) ? (string) $metadata['description'] : null,
                    'purpose' => isset($metadata['purpose']) ? (string) $metadata['purpose'] : null,
                    'project' => isset($metadata['project']) ? (string) $metadata['project'] : null,
                    'output_format' => $format,
                    'renderer_targets' => is_array($metadata['renderer_targets'] ?? null) ? $metadata['renderer_targets'] : [],
                    'ownership_scope' => Template::SCOPE_PLATFORM,
                    'status' => Template::STATUS_DRAFT,
                ]);
            } else {
                if ($template->ownership_scope !== Template::SCOPE_PLATFORM) {
                    throw new LogicException("Built-in template [{$key}] conflicts with a non-platform template.");
                }
                if ($template->status === Template::STATUS_ARCHIVED) {
                    throw new LogicException("Archived template [{$key}] cannot be re-registered.");
                }
                if ($template->output_format !== $format) {
                    throw new LogicException("Template [{$key}] cannot change output format from [{$template->output_format}] to [{$format}].");
                }
                if ($template->published_version_id === null) {
                    $template->fill([
                        'name' => $name,
                        'description' => isset($metadata['description']) ? (string) $metadata['description'] : null,
                        'purpose' => isset($metadata['purpose']) ? (string) $metadata['purpose'] : null,
                        'project' => isset($metadata['project']) ? (string) $metadata['project'] : null,
                        'renderer_targets' => is_array($metadata['renderer_targets'] ?? null) ? $metadata['renderer_targets'] : [],
                    ])->save();
                }
            }

            $existing = TemplateVersion::query()
                ->where('template_id', $template->getKey())
                ->where('definition_sha256', $hash)
                ->where('schema_version', $schemaVersion)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $version = (int) TemplateVersion::query()->where('template_id', $template->getKey())->max('version') + 1;

            return TemplateVersion::query()->create([
                'template_id' => $template->getKey(),
                'version' => $version,
                'status' => TemplateVersion::STATUS_DRAFT,
                'source_repository' => $sourceRepository,
                'source_revision' => strtolower($sourceRevision),
                'definition_path' => $definitionPath,
                'definition_sha256' => $hash,
                'definition_artifact_path' => $artifactPath,
                'schema_version' => $schemaVersion,
                'catalog_metadata' => [
                    'name' => $name,
                    'description' => isset($metadata['description']) ? (string) $metadata['description'] : null,
                    'purpose' => isset($metadata['purpose']) ? (string) $metadata['purpose'] : null,
                    'project' => isset($metadata['project']) ? (string) $metadata['project'] : null,
                    'renderer_targets' => is_array($metadata['renderer_targets'] ?? null) ? $metadata['renderer_targets'] : [],
                ],
                'input_contract' => is_array($metadata['input_contract'] ?? null) ? $metadata['input_contract'] : [],
                'media_contract' => is_array($metadata['media_contract'] ?? null) ? $metadata['media_contract'] : [],
                'rendering_contract' => is_array($metadata['rendering_contract'] ?? null) ? $metadata['rendering_contract'] : [],
                'created_by' => $actor?->getKey(),
            ]);
        });
    }

    public function publish(TemplateVersion $version, string $definitionContents): TemplateVersion
    {
        return DB::transaction(function () use ($version, $definitionContents): TemplateVersion {
            /** @var TemplateVersion $initialVersion */
            $initialVersion = TemplateVersion::query()->findOrFail($version->getKey());
            /** @var Template $template */
            $template = Template::query()->lockForUpdate()->findOrFail($initialVersion->template_id);
            /** @var TemplateVersion $version */
            $version = TemplateVersion::query()->lockForUpdate()->findOrFail($version->getKey());
            if ((int) $version->template_id !== (int) $template->getKey()) {
                throw new LogicException('Template version ownership changed during publication.');
            }
            $this->assertDefinitionMatches($version, $definitionContents);
            $artifactContents = $this->getDefinitionContents($version);
            if (! hash_equals(hash('sha256', $definitionContents), hash('sha256', $artifactContents))) {
                throw new RuntimeException('The source definition and immutable R2 artifact do not match.');
            }
            $definition = json_decode($definitionContents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($definition)) {
                throw new RuntimeException('The pinned template definition must contain a JSON object.');
            }
            $metadata = $this->definitionAdapter->normalize($definition, $version->definition_path);
            $this->assertVersionContractMatches($version, $metadata);
            if ($template->status === Template::STATUS_ARCHIVED) {
                throw new LogicException('Archived templates cannot publish versions.');
            }
            if ($version->status !== TemplateVersion::STATUS_DRAFT) {
                throw new LogicException('Only draft template versions can be published.');
            }

            $previous = $template->publishedVersion;
            if ($previous !== null && (int) $previous->getKey() !== (int) $version->getKey()) {
                $previous->retire()->save();
            }
            $version->publish()->save();
            $catalogMetadata = $version->getAttribute('catalog_metadata');
            if (! is_array($catalogMetadata)) {
                $catalogMetadata = [];
            }
            $template->fill([
                'name' => $catalogMetadata['name'] ?? $template->name,
                'description' => $catalogMetadata['description'] ?? null,
                'purpose' => $catalogMetadata['purpose'] ?? null,
                'project' => $catalogMetadata['project'] ?? null,
                'renderer_targets' => $catalogMetadata['renderer_targets'] ?? [],
            ]);
            $template->publishVersion($version)->save();

            return $version->refresh();
        });
    }

    public function archive(Template $template): Template
    {
        return DB::transaction(function () use ($template): Template {
            /** @var Template $template */
            $template = Template::query()->lockForUpdate()->findOrFail($template->getKey());
            if ($template->status === Template::STATUS_ARCHIVED) {
                return $template;
            }
            $publishedVersion = $template->publishedVersion;
            if ($publishedVersion !== null) {
                $publishedVersion->retire()->save();
            }
            $template->archive()->save();

            return $template->refresh();
        });
    }

    /** @return Collection<int, Template> */
    public function publishedTemplates(?string $outputFormat = null): Collection
    {
        $templates = Template::query()
            ->where('status', Template::STATUS_PUBLISHED)
            ->whereNotNull('published_version_id')
            ->when($outputFormat !== null, fn ($query) => $query->where('output_format', $outputFormat))
            ->with('publishedVersion')
            ->orderBy('name')
            ->get();

        foreach ($templates as $template) {
            if ($template->publishedVersion === null) {
                throw new RuntimeException("Published template [{$template->key}] has no published version.");
            }
            $this->validateStoredDefinition($template->publishedVersion);
        }

        return $templates;
    }

    public function resolvePublished(string $key, ?string $outputFormat = null, ?int $versionNumber = null): TemplateVersion
    {
        $template = Template::query()
            ->where('key', $key)
            ->where('status', Template::STATUS_PUBLISHED)
            ->when($outputFormat !== null, fn ($query) => $query->where('output_format', $outputFormat))
            ->first();

        if ($template === null) {
            throw (new ModelNotFoundException)->setModel(Template::class, [$key]);
        }
        if ($versionNumber !== null) {
            $version = $template->versions()->where('version', $versionNumber)->where('status', TemplateVersion::STATUS_PUBLISHED)->first();
            if ($version === null) {
                throw (new ModelNotFoundException)->setModel(TemplateVersion::class, [$key, $versionNumber]);
            }

            $this->validateStoredDefinition($version);

            return $version;
        }

        $version = $template->publishedVersion;
        if ($version === null || $version->status !== TemplateVersion::STATUS_PUBLISHED) {
            throw (new ModelNotFoundException)->setModel(TemplateVersion::class, [$key]);
        }

        $this->validateStoredDefinition($version);

        return $version;
    }

    public function getDefinitionContents(TemplateVersion $version): string
    {
        $path = $version->definition_artifact_path;
        $disk = Storage::disk('r2');
        if (! $disk->exists($path)) {
            throw new RuntimeException('The immutable R2 template definition artifact is unavailable.');
        }
        try {
            $contents = $disk->get($path);
        } catch (\Throwable $exception) {
            throw new RuntimeException('The immutable R2 template definition artifact could not be read.', previous: $exception);
        }
        if (! is_string($contents)) {
            throw new RuntimeException('The immutable R2 template definition artifact returned invalid contents.');
        }
        $this->assertDefinitionMatches($version, $contents);

        return $contents;
    }

    private function validateStoredDefinition(TemplateVersion $version): void
    {
        $contents = $this->getDefinitionContents($version);
        $definition = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($definition)) {
            throw new RuntimeException('The immutable template definition is not a JSON object.');
        }
        $this->assertVersionContractMatches($version, $this->definitionAdapter->normalize($definition, $version->definition_path));
    }

    /** @param array<string, mixed> $inputs */
    public function validateInputs(TemplateVersion $version, array $inputs): void
    {
        $contract = $version->getAttribute('input_contract');
        if (! is_array($contract)) {
            throw new RuntimeException('The registered template input contract is malformed.');
        }
        if ($contract === []) {
            return;
        }

        $required = $contract['required'] ?? [];
        $optional = $contract['optional'] ?? [];
        $fields = $contract['fields'] ?? [];
        if (! is_array($required) || ! is_array($optional) || ! is_array($fields)) {
            throw new RuntimeException('The registered template input contract is malformed.');
        }

        $allowed = array_unique(array_merge($required, $optional, array_keys($fields)));
        if (($contract['additional_properties'] ?? false) !== true) {
            $unknown = array_diff(array_keys($inputs), $allowed);
            if ($unknown !== []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'template_inputs' => 'Unsupported template input fields: '.implode(', ', $unknown),
                ]);
            }
        }

        $rules = [];
        foreach ($required as $field) {
            if (! is_string($field) || $field === '') {
                throw new RuntimeException('Template input contract required fields must be non-empty strings.');
            }
            $rules[$field][] = 'required';
        }
        foreach ($optional as $field) {
            if (! is_string($field) || $field === '') {
                throw new RuntimeException('Template input contract optional fields must be non-empty strings.');
            }
            $rules[$field][] = 'sometimes';
        }
        foreach ($fields as $field => $definition) {
            if (! is_string($field) || ! is_array($definition)) {
                throw new RuntimeException('Template input contract fields must map names to rule objects.');
            }
            $rules[$field] ??= ['sometimes'];
            $typeRule = match ($definition['type'] ?? null) {
                'string' => 'string',
                'integer' => 'integer',
                'number' => 'numeric',
                'boolean' => 'boolean',
                'array' => 'array',
                'object' => 'array',
                null => null,
                default => throw new RuntimeException("Unsupported template input type for [{$field}]."),
            };
            if ($typeRule !== null) {
                $rules[$field][] = $typeRule;
            }
            foreach (['min_length' => 'min', 'max_length' => 'max', 'min' => 'min', 'max' => 'max'] as $source => $rule) {
                if (isset($definition[$source])) {
                    if (! is_numeric($definition[$source]) || $definition[$source] < 0) {
                        throw new RuntimeException("Invalid [{$source}] constraint for template input [{$field}].");
                    }
                    $rules[$field][] = $rule.':'.$definition[$source];
                }
            }
            if (isset($definition['enum'])) {
                if (! is_array($definition['enum']) || $definition['enum'] === []) {
                    throw new RuntimeException("Invalid enum constraint for template input [{$field}].");
                }
                $rules[$field][] = \Illuminate\Validation\Rule::in($definition['enum']);
            }
        }

        $validator = \Illuminate\Support\Facades\Validator::make($inputs, $rules);
        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertVersionContractMatches(TemplateVersion $version, array $metadata): void
    {
        $template = $version->template;
        if ($template === null
            || $metadata['key'] !== $template->key
            || $metadata['output_format'] !== $template->output_format
            || $metadata['schema_version'] !== $version->schema_version
            || ($metadata['input_contract'] ?? []) !== ($version->input_contract ?? [])
            || ($metadata['media_contract'] ?? []) !== ($version->media_contract ?? [])
            || ($metadata['rendering_contract'] ?? []) !== ($version->rendering_contract ?? [])) {
            throw new RuntimeException('The pinned definition metadata or contracts do not match the registered template version.');
        }

        $catalogMetadata = $version->getAttribute('catalog_metadata');
        if (! is_array($catalogMetadata)) {
            $catalogMetadata = [];
        }
        foreach (['name', 'description', 'purpose', 'project', 'renderer_targets'] as $field) {
            if (($metadata[$field] ?? null) !== ($catalogMetadata[$field] ?? null)) {
                throw new RuntimeException("The pinned definition catalogue field [{$field}] does not match the registered version.");
            }
        }
    }

    public function assertDefinitionMatches(TemplateVersion $version, string $contents): void
    {
        if (! hash_equals($version->definition_sha256, hash('sha256', $contents))) {
            throw new RuntimeException("Template definition integrity check failed for [{$version->template?->key}@{$version->version}].");
        }
    }
}
