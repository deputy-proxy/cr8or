<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'approval_request_id',
    'organization_id',
    'enterprise_id',
    'stage',
    'decision',
    'actor_id',
    'actor_name',
    'reason',
    'decided_at',
])]
class ApprovalDecision extends Model
{
    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_CANCELLED = 'cancelled';

    public const DECISION_EXPIRED = 'expired';

    public const DECISION_STALE = 'stale';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (ApprovalDecision $decision): void {
            $request = ApprovalRequest::query()->find($decision->approval_request_id);

            if ($request === null
                || (int) $request->organization_id !== (int) $decision->organization_id
                || (int) $request->enterprise_id !== (int) $decision->enterprise_id
            ) {
                throw new LogicException('Approval decision must belong to its approval request scope.');
            }

            if (! in_array($decision->decision, [
                self::DECISION_APPROVED,
                self::DECISION_REJECTED,
                self::DECISION_CANCELLED,
                self::DECISION_EXPIRED,
                self::DECISION_STALE,
            ], true)) {
                throw new LogicException("Invalid approval decision [{$decision->decision}].");
            }

            if ($decision->exists) {
                throw new LogicException('Approval decisions are immutable.');
            }
        });
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}