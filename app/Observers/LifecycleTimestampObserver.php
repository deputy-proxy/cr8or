<?php

namespace App\Observers;

use App\Support\LifecycleStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Maintains the latest close timestamp used by snapshot-based lifecycle reporting.
 *
 * Tasks also retain immutable lifecycle Events so repeated close/reopen intervals can be
 * reconstructed without treating this latest-state timestamp as a complete history.
 * Timestamp maintenance remains separate from activity recording.
 */
final class LifecycleTimestampObserver
{
    public function saving(Model $model): void
    {
        $status = $model->getAttribute('status');
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
