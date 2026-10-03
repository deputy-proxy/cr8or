<?php

namespace App\Models;

use Database\Factories\GenerationJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['generation_request_id', 'external_job_id', 'status', 'failure_reason'])]
class GenerationJob extends Model
{
    /** @use HasFactory<GenerationJobFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** @return BelongsTo<GenerationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(GenerationRequest::class, 'generation_request_id');
    }
}