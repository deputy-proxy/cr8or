<?php

namespace App\Models;

use Database\Factories\KnowledgeDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['enterprise_id', 'knowledge_source_id', 'title', 'identifier', 'status', 'content', 'metadata'])]
class KnowledgeDocument extends Model
{
    /** @use HasFactory<KnowledgeDocumentFactory> */
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

    /** @return HasMany<KnowledgeItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class, 'knowledge_document_id');
    }

    /** @return HasMany<KnowledgeReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(KnowledgeReference::class, 'knowledge_document_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}