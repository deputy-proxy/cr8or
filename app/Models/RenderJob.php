<?php

namespace App\Models;

use Database\Factories\RenderJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['render_request_id', 'workflow_job_id', 'execution_id', 'external_job_id', 'status', 'failure_reason'])]
class RenderJob extends Model
{
    /** @use HasFactory<RenderJobFactory> */
    use HasFactory;

    /** @return BelongsTo<RenderRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RenderRequest::class, 'render_request_id');
    }

    /** @return BelongsTo<Job, $this> */
    public function workflowJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'workflow_job_id');
    }

    /** @return BelongsTo<Execution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }
}
