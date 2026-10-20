<?php

namespace App\Models;

use App\Models\Pivots\PersonaProduct;
use Database\Factories\PersonaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'name', 'description', 'status', 'persona_type', 'age_range', 'location', 'job_title', 'income_range', 'education_level', 'family_status', 'research_confidence', 'last_reviewed_at', 'psychographics', 'goals_and_motivations', 'pain_points', 'buying_behavior'])]
class Persona extends Model
{
    /** @use HasFactory<PersonaFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const PERSONA_TYPES = ['buyer', 'user', 'influencer', 'decision_maker', 'gatekeeper'];

    protected function casts(): array
    {
        return [
            'research_confidence' => 'decimal:2',
            'last_reviewed_at' => 'date',
            'psychographics' => 'array',
            'goals_and_motivations' => 'array',
            'pain_points' => 'array',
            'buying_behavior' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Persona $persona): void {
            if (! in_array($persona->status, [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
                throw new LogicException("Invalid persona status [{$persona->status}].");
            }

            if ($persona->exists && $persona->isDirty('enterprise_id')) {
                throw new LogicException('Persona enterprise ownership cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsToMany<Product, $this, PersonaProduct> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->using(PersonaProduct::class)->withTimestamps();
    }

    /** @return HasMany<ContentItem, $this> */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }
}