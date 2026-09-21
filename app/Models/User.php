<?php

namespace AppModels;

use DatabaseFactoriesUserFactory;
use FilamentModelsContractsFilamentUser;
use FilamentPanel;
use IlluminateDatabaseEloquentAttributesFillable;
use IlluminateDatabaseEloquentAttributesHidden;
use IlluminateDatabaseEloquentFactoriesHasFactory;
use IlluminateDatabaseEloquentRelationsBelongsToMany;
use IlluminateDatabaseEloquentRelationsHasMany;
use IlluminateFoundationAuthUser as Authenticatable;
use IlluminateNotificationsNotifiable;
use IlluminateSupportStr;
use LaravelFortifyContractsPasskeyUser;
use LaravelFortifyPasskeyAuthenticatable;
use LaravelFortifyTwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->exists;
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
