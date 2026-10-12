<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * An organization-owned authenticated identity on an external social network.
 * It stores identity metadata only; credentials and authorization lifecycle belong to IntegrationConnection.
 */
#[Fillable(['organization_id', 'provider', 'external_id', 'name'])]
class SocialIdentity extends Model
{
    /** @use HasFactory<\Database\Factories\SocialIdentityFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $identity): void {
            foreach (['provider' => 100, 'name' => 255] as $field => $limit) {
                if (! $identity->isDirty($field)) {
                    continue;
                }

                $value = $identity->{$field};
                if ($value === null && $field === 'name') {
                    continue;
                }
                if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $limit) {
                    throw ValidationException::withMessages([$field => "The {$field} field is invalid."]);
                }
                $identity->{$field} = trim($value);
            }

            $externalId = $identity->external_id;
            if (trim($externalId) === '' || $externalId !== trim($externalId) || mb_strlen($externalId) > 255) {
                throw ValidationException::withMessages(['external_id' => 'The external identity identifier must be non-empty, unpadded, and at most 255 characters.']);
            }

            if ($identity->exists && ($identity->isDirty('organization_id') || $identity->isDirty('provider') || $identity->isDirty('external_id'))) {
                throw new LogicException('Social identity ownership and external identity are immutable; create a new identity instead.');
            }

            if (! $identity->exists && self::query()
                ->where('organization_id', $identity->organization_id)
                ->where('provider', $identity->provider)
                ->where('external_id', $identity->external_id)
                ->exists()) {
                throw ValidationException::withMessages(['external_id' => 'This external identity is already registered in the organization.']);
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<SocialAccount, $this> */
    public function destinations(): HasMany
    {
        return $this->hasMany(SocialAccount::class, 'social_identity_id');
    }

    /** @return HasOne<IntegrationConnection, $this> */
    public function integrationConnection(): HasOne
    {
        return $this->hasOne(IntegrationConnection::class, 'social_identity_id');
    }
}
