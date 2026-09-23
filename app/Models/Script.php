<?php

namespace App\Models;

use Database\Factories\ScriptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_item_id', 'title', 'body'])]
class Script extends Model
{
    /** @use HasFactory<ScriptFactory> */
    use HasFactory;

    /** @return BelongsTo<ContentItem, $this> */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
