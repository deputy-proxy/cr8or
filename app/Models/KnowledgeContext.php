<?php

namespace App\Models;

use Database\Factories\KnowledgeContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['enterprise_id', 'name', 'type', 'description', 'data'])]
class KnowledgeContext extends Model
{
    /** @use HasFactory<KnowledgeContextFactory> */
    use HasFactory;

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return HasMany<KnowledgeItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class, 'knowledge_context_id');
    }

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
