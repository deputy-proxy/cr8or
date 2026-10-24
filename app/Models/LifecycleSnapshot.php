<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'enterprise_id', 'snapshot_date', 'boundary', 'entity_type', 'open_count', 'is_complete', 'metadata', 'boundary_at', 'captured_at'])]
class LifecycleSnapshot extends Model
{
    public const BOUNDARY_START = 'start';

    public const BOUNDARY_END = 'end';

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'immutable_date',
            'open_count' => 'integer',
            'is_complete' => 'boolean',
            'metadata' => 'array',
            'boundary_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}
