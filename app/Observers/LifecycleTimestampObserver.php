<?php

namespace App\Observers;

use App\Support\LifecycleStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Maintains the single close timestamp used by lifecycle reporting.
 *
 * This observer is intentionally separate from activity recording: timestamps are
 * authoritative domain data and must not depend on the activity-feed implementation.
 */
final class LifecycleTimestampObserver
{
    public function saving(Model $model): void
    {
        $status = $model->getAttribute('status');
        if (! is_string($status)) {
            return;
        }

        $isClosed = LifecycleStatus::isClosed($status);

        if (! $model->exists) {
            $model->setAttribute('closed_at', $isClosed ? now() : null);

            return;
        }

        $wasClosed = LifecycleStatus::isClosed($model->getRawOriginal('status'));

        if ($isClosed && ! $wasClosed) {
            $model->setAttribute('closed_at', now());

            return;
        }

        if (! $isClosed) {
            $model->setAttribute('closed_at', null);
        }
    }
}
