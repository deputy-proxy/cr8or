<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Fillable(['enterprise_id', 'channel_id', 'provider', 'name', 'external_id', 'status'])]
class SocialAccount extends Model
{
    /** @use HasFactory<\Database\Factories\SocialAccountFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    /**
     * Normalize user-facing social account fields and validate opaque provider identifiers.
     * Provider identifiers are never case-folded or trimmed; padded identifiers are rejected
     * rather than silently rewritten and potentially changed into a different external identity.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeInput(array $attributes): array
    {
        $errors = [];

        foreach (['provider' => 100, 'name' => 255] as $field => $maximumLength) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];
            if (! is_string($value)) {
                $errors[$field] = 'The field must be a string.';

                continue;
            }

            $value = trim($value);
            if ($value === '') {
                $errors[$field] = 'The field cannot be empty or contain only whitespace.';

                continue;
            }

            if (mb_strlen($value) > $maximumLength) {
                $errors[$field] = "The field may not exceed {$maximumLength} characters.";

                continue;
            }

            $attributes[$field] = $value;
        }

        if (array_key_exists('external_id', $attributes)) {
            $externalId = $attributes['external_id'];

            if (! is_string($externalId)) {
                $errors['external_id'] = 'The provider identifier must be a string.';
            } elseif (trim($externalId) === '') {
                $errors['external_id'] = 'The provider identifier cannot be empty or contain only whitespace.';
            } elseif ($externalId !== trim($externalId)) {
                $errors['external_id'] = 'The provider identifier must not have leading or trailing whitespace. Provider identifiers are preserved exactly.';
            } elseif (mb_strlen($externalId) > 255) {
                $errors['external_id'] = 'The provider identifier may not exceed 255 characters.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $attributes;
    }

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if (! in_array($account->status, [self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
                throw new LogicException("Invalid social account status [{$account->status}].");
            }

            $fieldsToNormalize = [];
            foreach (['provider', 'name', 'external_id'] as $field) {
                if (! $account->exists || $account->isDirty($field)) {
                    $fieldsToNormalize[$field] = $account->{$field};
                }
            }

            foreach (self::normalizeInput($fieldsToNormalize) as $field => $value) {
                $account->{$field} = $value;
            }

            $channel = Channel::query()->find($account->channel_id);
            if ($channel === null) {
                throw new LogicException('Social account requires an existing channel.');
            }

            if ($account->exists && (
                (int) $account->getOriginal('enterprise_id') !== (int) $channel->enterprise_id
                || ($account->isDirty('enterprise_id') && (int) $account->enterprise_id !== (int) $account->getOriginal('enterprise_id'))
            )) {
                throw new LogicException('Social account enterprise ownership cannot be changed.');
            }

            if (! $account->exists && $account->isDirty('enterprise_id') && (int) $account->enterprise_id !== (int) $channel->enterprise_id) {
                throw new LogicException('Social account must use a channel from its enterprise.');
            }

            if (! $account->exists || $account->isDirty('provider') || $account->isDirty('external_id')) {
                $duplicate = static::query()
                    ->where('provider', $account->provider)
                    ->where('external_id', $account->external_id)
                    ->when($account->exists, fn ($query) => $query->where($account->getKeyName(), '!=', $account->getKey()))
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'external_id' => 'This provider identifier is already connected for this provider.',
                    ]);
                }
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
