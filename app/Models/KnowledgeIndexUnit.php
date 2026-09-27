<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'enterprise_id',
    'knowledge_index_record_id',
    'knowledge_item_id',
    'knowledge_version_id',
    'unit_key',
    'ordinal',
    'content',
    'content_hash',
    'heading_path',
    'references',
    'metadata',
])]
/**
 * @property list<array<string, mixed>>|null $references
 */
class KnowledgeIndexUnit extends Model
{
    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<KnowledgeIndexRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(KnowledgeIndexRecord::class, 'knowledge_index_record_id');
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
            'heading_path' => 'array',
            'references' => 'array',
            'metadata' => 'array',
        ];
    }
}