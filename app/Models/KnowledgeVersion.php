<?php

namespace App\Models;

use Database\Factories\KnowledgeVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['enterprise_id', 'knowledge_item_id', 'version', 'content', 'context_snapshot', 'recorded_at'])]
class KnowledgeVersion extends Model
{
    /** @use HasFactory<KnowledgeVersionFactory> */
    use HasFactory;

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<KnowledgeItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            if ($version->getAttribute('version') === null) {
                $version->setAttribute('version', ((int) self::query()->where('knowledge_item_id', $version->getAttribute('knowledge_item_id'))->max('version')) + 1);
            }
            if ($version->getAttribute('recorded_at') === null) {
                $version->setAttribute('recorded_at', now());
            }
        });
    }

    protected function casts(): array
    {
        return ['context_snapshot' => 'array', 'recorded_at' => 'datetime'];
    }
}
