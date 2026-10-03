<?php

namespace App\Models;

use Database\Factories\RenderJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['render_request_id', 'external_job_id', 'status', 'failure_reason'])]
class RenderJob extends Model
{
    /** @use HasFactory<RenderJobFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** @return BelongsTo<RenderRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RenderRequest::class, 'render_request_id');
    }
}