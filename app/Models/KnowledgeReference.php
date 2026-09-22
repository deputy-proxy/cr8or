<?php

namespace App\Models;

use Database\Factories\KnowledgeReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['enterprise_id', 'knowledge_item_id', 'knowledge_document_id', 'type', 'label', 'locator', 'metadata'])]
class KnowledgeReference extends Model
{
    /** @use HasFactory<KnowledgeReferenceFactory> */
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

    /** @return BelongsTo<KnowledgeDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}