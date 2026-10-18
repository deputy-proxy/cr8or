<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['key', 'name', 'description', 'purpose', 'project', 'output_format', 'renderer_targets', 'ownership_scope', 'organization_id', 'enterprise_id', 'user_id', 'status', 'published_version_id'])]
class Template extends Model
{
    private bool $allowPublicationTransition = false;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_ENTERPRISE = 'enterprise';

    public const SCOPE_USER = 'user';

    protected function casts(): array
    {
        return ['renderer_targets' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Template $template): void {
            if (! $template->exists && $template->status !== self::STATUS_DRAFT) {
                throw new LogicException('Templates must be registered as drafts before publication.');
            }
            if ($template->exists && $template->isDirty(['status', 'published_version_id']) && ! $template->allowPublicationTransition) {
                throw new LogicException('Template publication state must be changed through TemplateCatalogService.');
            }
            if ($template->exists
                && in_array($template->getOriginal('status'), [self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true)
                && $template->isDirty()
                && ! $template->allowPublicationTransition) {
                throw new LogicException('Published template metadata is managed by TemplateCatalogService.');
            }
            if (! in_array($template->status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true)) {
                throw new LogicException("Invalid template status [{$template->status}].");
            }
            $all = [$template->organization_id, $template->enterprise_id, $template->user_id];
            $expected = match ($template->ownership_scope) {
                self::SCOPE_PLATFORM => [],
                self::SCOPE_ORGANIZATION => [$template->organization_id],
                self::SCOPE_ENTERPRISE => [$template->enterprise_id],
                self::SCOPE_USER => [$template->user_id],
                default => throw new LogicException('Invalid template ownership scope.'),
            };
            if (($template->ownership_scope !== self::SCOPE_PLATFORM && (in_array(null, $expected, true) || count(array_filter($all, fn ($id) => $id !== null)) !== 1))
                || ($template->ownership_scope === self::SCOPE_PLATFORM && count(array_filter($all, fn ($id) => $id !== null)) !== 0)) {
                throw new LogicException('Template ownership scope must match exactly one owner reference, or none for platform-owned templates.');
            }
            if ($template->status === self::STATUS_PUBLISHED && $template->published_version_id === null) {
                throw new LogicException('Published templates must reference their current published version.');
            }
            if ($template->status !== self::STATUS_PUBLISHED && $template->published_version_id !== null) {
                throw new LogicException('Only published templates may reference a current published version.');
            }
            if ($template->published_version_id !== null) {
                /** @var TemplateVersion|null $version */
                $version = TemplateVersion::query()->find($template->published_version_id);
                if ($version === null || (int) $version->template_id !== (int) $template->getKey() || $version->status !== TemplateVersion::STATUS_PUBLISHED) {
                    throw new LogicException('Published template version must be published and belong to the template.');
                }
            }
        });
        static::saved(function (Template $template): void {
            $template->allowPublicationTransition = false;
        });
    }

    public function publishVersion(TemplateVersion $version): static
    {
        if ((int) $version->template_id !== (int) $this->getKey() || $version->status !== TemplateVersion::STATUS_PUBLISHED) {
            throw new LogicException('Only a published version belonging to this template can be selected.');
        }
        $this->published_version_id = $version->getKey();
        $this->status = self::STATUS_PUBLISHED;
        $this->allowPublicationTransition = true;

        return $this;
    }

    public function archive(): static
    {
        $this->published_version_id = null;
        $this->status = self::STATUS_ARCHIVED;
        $this->allowPublicationTransition = true;

        return $this;
    }

    /** @return HasMany<TemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class)->orderByDesc('version');
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'published_version_id');
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
