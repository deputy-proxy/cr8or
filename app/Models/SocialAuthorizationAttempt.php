<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $user_id
 * @property int $organization_id
 * @property int|null $enterprise_id
 * @property int|null $channel_id
 * @property string $provider
 * @property string $destination_network
 * @property string $state_hash
 * @property string $code_verifier_encrypted
 * @property string $redirect_uri
 * @property list<string> $requested_scopes
 * @property string $status
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable|null $consumed_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property list<array<string, mixed>>|null $discovered_destinations
 * @property list<string>|null $selected_destinations
 * @property \Carbon\CarbonImmutable|null $destinations_attached_at
 * @property int|null $social_identity_id
 * @property int|null $integration_connection_id
 * @property string|null $failure_code
 * @property string $correlation_id
 */
#[Fillable([
    'user_id',
    'organization_id',
    'enterprise_id',
    'channel_id',
    'provider',
    'destination_network',
    'state_hash',
    'code_verifier_encrypted',
    'redirect_uri',
    'requested_scopes',
    'status',
    'expires_at',
    'consumed_at',
    'completed_at',
    'discovered_destinations',
    'selected_destinations',
    'destinations_attached_at',
    'social_identity_id',
    'integration_connection_id',
    'failure_code',
    'correlation_id',
])]
class SocialAuthorizationAttempt extends Model
{
    private bool $transitionAuthorized = false;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DENIED = 'denied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $hidden = ['state_hash', 'code_verifier_encrypted'];

    protected function casts(): array
    {
        return [
            'requested_scopes' => 'array',
            'discovered_destinations' => 'array',
            'selected_destinations' => 'array',
            'destinations_attached_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $attempt): void {
            if (! preg_match('/^[a-f0-9]{64}$/', $attempt->state_hash)) {
                throw new LogicException('Social authorization state must be stored as a SHA-256 hash.');
            }

            if ($attempt->code_verifier_encrypted === '') {
                throw new LogicException('Social authorization attempts require an encrypted PKCE verifier.');
            }

            if ($attempt->expires_at === null || $attempt->expires_at->isPast()) {
                throw new LogicException('Social authorization attempts must have a future expiry.');
            }
        });

        static::saving(function (self $attempt): void {
            if (! $attempt->exists) {
                return;
            }

            if ($attempt->isDirty('status')) {
                if (! $attempt->transitionAuthorized) {
                    throw new LogicException('Social authorization attempt status can only change through its lifecycle methods.');
                }

                $attempt->transitionAuthorized = false;
            }

            foreach (['user_id', 'organization_id', 'enterprise_id', 'provider', 'destination_network', 'state_hash', 'code_verifier_encrypted', 'redirect_uri', 'requested_scopes', 'expires_at', 'correlation_id'] as $field) {
                if ($attempt->isDirty($field)) {
                    throw new LogicException('Social authorization attempt identity and state are immutable.');
                }
            }

            if ($attempt->isDirty('channel_id') && ($attempt->getOriginal('channel_id') !== null || $attempt->status !== self::STATUS_COMPLETED || $attempt->getOriginal('destinations_attached_at') !== null)) {
                throw new LogicException('A social authorization attempt can select its destination channel only once after completion.');
            }

            if ($attempt->isDirty('selected_destinations') && $attempt->getOriginal('destinations_attached_at') !== null) {
                throw new LogicException('Selected social destinations are immutable after attachment.');
            }

            if (! in_array($attempt->status, [
                self::STATUS_PENDING,
                self::STATUS_PROCESSING,
                self::STATUS_COMPLETED,
                self::STATUS_DENIED,
                self::STATUS_FAILED,
                self::STATUS_EXPIRED,
            ], true)) {
                throw new LogicException('Invalid social authorization attempt status.');
            }
        });
    }

    public function transitionTo(string $status): static
    {
        $allowed = match ($this->status) {
            self::STATUS_PENDING => [self::STATUS_PROCESSING, self::STATUS_DENIED, self::STATUS_FAILED, self::STATUS_EXPIRED],
            self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_DENIED, self::STATUS_FAILED],
            default => [],
        };

        if (! in_array($status, $allowed, true)) {
            throw new LogicException("Invalid social authorization attempt transition from {$this->status} to {$status}.");
        }

        $this->status = $status;
        $this->transitionAuthorized = true;

        return $this;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<SocialIdentity, $this> */
    public function socialIdentity(): BelongsTo
    {
        return $this->belongsTo(SocialIdentity::class);
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }
}