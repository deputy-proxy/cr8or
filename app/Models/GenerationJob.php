<?php

namespace App\Models;

use Database\Factories\GenerationJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['generation_request_id', 'workflow_job_id', 'execution_id', 'external_job_id', 'status', 'failure_reason'])]
class GenerationJob extends Model
{
    /** @use HasFactory<GenerationJobFactory> */
    use HasFactory;

    /** @return BelongsTo<GenerationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(GenerationRequest::class, 'generation_request_id');
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