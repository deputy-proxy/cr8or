<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['organization_id', 'enterprise_id', 'provider', 'external_account_id', 'credential_reference', 'status', 'metadata'])]
class IntegrationConnection extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (IntegrationConnection $connection): void {
            if (! in_array($connection->status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
                throw new LogicException("Invalid integration connection status [{$connection->status}].");
            }

            if ($connection->credential_reference === '') {
                throw new LogicException('Integration connections require a credential reference.');
            }

            if ($connection->enterprise_id !== null) {
                $enterprise = Enterprise::query()->find($connection->enterprise_id);
                if ($enterprise === null || (int) $enterprise->organization_id !== (int) $connection->organization_id) {
                    throw new LogicException('Integration connection enterprise must belong to its organization.');
                }
            }

            if ($connection->exists && $connection->isDirty('organization_id')) {
                throw new LogicException('Integration connection organization cannot be changed.');
            }
        });
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
