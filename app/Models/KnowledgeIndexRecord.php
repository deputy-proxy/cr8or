<?php

namespace App\Models;

use App\Enums\KnowledgeIndexStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enterprise_id',
    'knowledge_source_id',
    'knowledge_document_id',
    'knowledge_item_id',
    'knowledge_version_id',
    'unit_key',
    'representation_key',
    'status',
    'content_hash',
    'provider',
    'provider_reference',
    'failure_code',
    'failure_message',
    'indexed_at',
    'invalidated_at',
    'metadata',
])]
class KnowledgeIndexRecord extends Model
{
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

    /** @return BelongsTo<KnowledgeItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }

    /** @return BelongsTo<KnowledgeVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(KnowledgeVersion::class, 'knowledge_version_id');
    }

    protected function casts(): array
    {
        return [
            'status' => KnowledgeIndexStatus::class,
            'indexed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}