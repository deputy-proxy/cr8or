<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['enterprise_id', 'channel_id', 'provider', 'name', 'external_id', 'status'])]
class SocialAccount extends Model
{
    /** @use HasFactory<\Database\Factories\SocialAccountFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if (! in_array($account->status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
                throw new LogicException("Invalid social account status [{$account->status}].");
            }
            $channel = Channel::query()->find($account->channel_id);
            if ($channel === null) {
                throw new LogicException('Social account requires an existing channel.');
            }
            if ($account->exists && $account->isDirty('enterprise_id') && (int) $account->getOriginal('enterprise_id') !== (int) $channel->enterprise_id) {
                throw new LogicException('Social account enterprise ownership cannot be changed.');
            }
            $account->enterprise_id = $channel->enterprise_id;
        });
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return HasMany<Publication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}