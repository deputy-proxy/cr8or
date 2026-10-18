<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['template_id', 'version', 'status', 'source_repository', 'source_revision', 'definition_path', 'definition_sha256', 'definition_artifact_path', 'schema_version', 'catalog_metadata', 'input_contract', 'media_contract', 'rendering_contract', 'created_by', 'published_at', 'retired_at'])]
class TemplateVersion extends Model
{
    private bool $allowLifecycleTransition = false;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    protected function casts(): array
    {
        return ['catalog_metadata' => 'array', 'input_contract' => 'array', 'media_contract' => 'array', 'rendering_contract' => 'array', 'published_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (TemplateVersion $version): void {
            if (! in_array($version->status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_RETIRED], true) || (int) $version->version < 1) {
                throw new LogicException('Template version status or version number is invalid.');
            }
            if (! $version->exists && $version->status !== self::STATUS_DRAFT) {
                throw new LogicException('TemplateVersions must be registered as drafts before publication.');
            }
            if ($version->exists && $version->isDirty()) {
                $originalStatus = $version->getOriginal('status');
                $dirtyFields = array_keys($version->getDirty());
                $isPublish = $originalStatus === self::STATUS_DRAFT
                    && $version->status === self::STATUS_PUBLISHED
                    && $version->allowLifecycleTransition
                    && $version->isDirty('published_at')
                    && array_diff($dirtyFields, ['status', 'published_at']) === [];
                $isRetirement = $originalStatus === self::STATUS_PUBLISHED
                    && $version->status === self::STATUS_RETIRED
                    && $version->allowLifecycleTransition
                    && $version->isDirty('retired_at')
                    && array_diff($dirtyFields, ['status', 'retired_at']) === [];
                if (! $isPublish && ! $isRetirement) {
                    throw new LogicException('TemplateVersion identity is immutable; register a new version instead of editing it.');
                }
            }

            $template = Template::query()->find($version->template_id);
            if ($template === null) {
                throw new LogicException('TemplateVersion must reference an existing template.');
            }
            if (! preg_match('/^[a-f0-9]{64}$/', (string) $version->definition_sha256)) {
                throw new LogicException('Template definition SHA-256 must be a lowercase 64-character hex digest.');
            }
            if (trim((string) $version->source_repository) === '' || trim((string) $version->source_revision) === '' || trim((string) $version->schema_version) === '') {
                throw new LogicException('Template version source repository, revision, and schema version are required.');
            }
            if (str_starts_with((string) $version->definition_path, '/') || str_contains((string) $version->definition_path, '..')) {
                throw new LogicException('Template definition path must be a safe relative path.');
            }
            $expectedArtifactPath = 'template-definitions/'.$template->key.'/'.$version->definition_sha256.'.json';
            if ($version->definition_artifact_path !== $expectedArtifactPath) {
                throw new LogicException('Template definition artifact path must match the stable key and definition hash.');
            }
        });
        static::saved(function (TemplateVersion $version): void {
            $version->allowLifecycleTransition = false;
        });
        static::deleting(function (TemplateVersion $version): void {
            if (in_array($version->status, [self::STATUS_PUBLISHED, self::STATUS_RETIRED], true)) {
                throw new LogicException('Published and retired TemplateVersions cannot be deleted.');
            }
        });
    }

    public function publish(): static
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new LogicException('Only draft TemplateVersions can be published.');
        }
        $this->status = self::STATUS_PUBLISHED;
        $this->published_at = now();
        $this->allowLifecycleTransition = true;

        return $this;
    }

    public function retire(): static
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            throw new LogicException('Only published TemplateVersions can be retired.');
        }
        $this->status = self::STATUS_RETIRED;
        $this->retired_at = now();
        $this->allowLifecycleTransition = true;

        return $this;
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}