<?php

namespace App\Models;

use Database\Factories\KnowledgeSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['enterprise_id', 'name', 'type', 'description', 'uri', 'metadata'])]
class KnowledgeSource extends Model
{
    /** @use HasFactory<KnowledgeSourceFactory> */
    use HasFactory;

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<KnowledgeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class, 'knowledge_source_id');
    }

    /** @return HasMany<KnowledgeItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class, 'knowledge_source_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
