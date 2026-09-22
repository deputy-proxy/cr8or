<?php

namespace App\Models;

use Database\Factories\KnowledgeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['enterprise_id', 'knowledge_source_id', 'knowledge_document_id', 'knowledge_context_id', 'title', 'type', 'summary'])]
class KnowledgeItem extends Model
{
    /** @use HasFactory<KnowledgeItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<KnowledgeSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    /** @return BelongsTo<KnowledgeDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    /** @return BelongsTo<KnowledgeContext, $this> */
    public function context(): BelongsTo
    {
        return $this->belongsTo(KnowledgeContext::class, 'knowledge_context_id');
    }

    /** @return HasMany<KnowledgeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeVersion::class);
    }

    /** @return HasMany<KnowledgeReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(KnowledgeReference::class);
    }

    /** @return HasMany<KnowledgeSpecification, $this> */
    public function specifications(): HasMany
    {
        return $this->hasMany(KnowledgeSpecification::class);
    }

    protected function casts(): array
    {
        return [];
    }
}