<?php

namespace App\Models;

use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['enterprise_id', 'repository', 'external_id', 'number', 'title', 'state', 'labels', 'author_login', 'github_created_at', 'github_updated_at', 'github_closed_at', 'url', 'last_synced_at'])]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['labels' => 'array', 'github_created_at' => 'immutable_datetime', 'github_updated_at' => 'immutable_datetime', 'github_closed_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}
