<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['enterprise_id', 'publication_id', 'publishing_job_id', 'provider', 'provider_status', 'external_id', 'external_url', 'correlation_id', 'failure_code', 'failure_reason', 'payload', 'recorded_at'])]
class PublicationResult extends Model
{
    /** @use HasFactory<\Database\Factories\PublicationResultFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $r): void {
            $p = Publication::query()->find($r->publication_id);
            if ($p === null || $p->enterprise_id !== (int) $r->enterprise_id) {
                throw new LogicException('Publication result enterprise mismatch.');
            }

            if ($r->exists) {
                throw new LogicException('Publication results are historical and immutable.');
            }
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /** @return BelongsTo<PublishingJob, $this> */
    public function publishingJob(): BelongsTo
    {
        return $this->belongsTo(PublishingJob::class);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'recorded_at' => 'datetime'];
    }
}
